<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * This compiler pass will register Monolog handler to handle exceptions through Monolog instead
 * of registering a global error handler. Symfony already registers an error handler itself and
 * there might be some issues when Sentry and Symfony both rely on having the error handler registered.
 *
 * By using this approach, we can instead subscribe to messages produces by Symfony that will carry
 * the exception as context. This will give us more compatibility since we just add more handler.
 *
 * Errors can arrive in different channels based on where the error came from, so we listen to multiple
 * channels to not miss exceptions.
 *
 * We will only listen to "internal" channels automatically, such as the `php` channel. We will not
 * listen to application channels by default. so adding ExceptionToSentryIssueHandler manually is still
 * required if desired.
 *
 * There are deliberately 2 handlers that are registered in this compiler pass:
 * 1. `PhpErrorHandler` will listen to internal PHP erros and deprecations
 * 2. `UncaughtExceptionHandler` will listen to any internal Symfony exceptions or uncaught exceptions that happened
 *
 * The reason for those handlers is logging overhead: in Monolog 1/2, isHandling is only invoked with the log level,
 * and we need to listen with DEBUG level to catch silenced errors. This would mean that all logs would be created
 * by monolog even though most might get discarded later in the `handle` method. Monolog 3 solves this by giving more
 * context to the `isHandling` call.
 */
final class MonologErrorReportingPass implements CompilerPassInterface
{
    /**
     * The channels Symfony's ErrorHandler logs PHP errors to.
     */
    private const PHP_ERROR_CHANNELS = ['php', 'deprecation'];

    /**
     * The channels Symfony logs uncaught exceptions to: of the HTTP kernel, of console commands and of messages.
     */
    private const UNCAUGHT_EXCEPTION_CHANNELS = ['request', 'console', 'messenger'];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID)) {
            return;
        }

        $uncaughtExceptionChannels = array_diff(
            array_unique(array_merge(self::UNCAUGHT_EXCEPTION_CHANNELS, $this->getExceptionLogChannels($container))),
            self::PHP_ERROR_CHANNELS
        );

        $this->pushHandler($container, SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID, $uncaughtExceptionChannels);

        if ($this->pushHandler($container, SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID, self::PHP_ERROR_CHANNELS)) {
            $this->enableScreamMode($container);
        }
    }

    /**
     * @param string[] $channels
     *
     * @return bool Whether the handler was pushed to at least one logger
     */
    private function pushHandler(ContainerBuilder $container, string $handlerId, array $channels): bool
    {
        $pushed = false;

        foreach ($channels as $channel) {
            // channel 'app' is an alias for just 'monolog.logger'
            $loggerId = 'app' === $channel ? 'monolog.logger' : 'monolog.logger.' . $channel;

            if (!$container->hasDefinition($loggerId)) {
                continue;
            }

            $container->getDefinition($loggerId)->addMethodCall('pushHandler', [new Reference($handlerId)]);
            $pushed = true;
        }

        return $pushed;
    }

    /**
     * Since Symfony 7.3, the "framework.exceptions.*.log_channel" option logs uncaught exceptions of the HTTP kernel
     * to other channels than "request". We want to auto register on this channel too to not lose exceptions.
     *
     * @return string[]
     */
    private function getExceptionLogChannels(ContainerBuilder $container): array
    {
        if (!$container->hasDefinition('exception_listener')) {
            return [];
        }

        $definition = $container->getDefinition('exception_listener');
        $position = $this->getConstructorArgumentPosition($container, $definition, 'loggers');

        if (null === $position) {
            return [];
        }

        $loggers = $definition->getArgument($position);

        return \is_array($loggers) ? array_map('strval', array_keys($loggers)) : [];
    }

    /**
     * Symfony's ErrorHandler only logs @-silenced errors in "scream" mode, which FrameworkBundle enables in debug
     * only. Enable it when silenced errors must be reported through Monolog.
     *
     * When the application doesn't scream itself, the PhpErrorHandler stops the propagation of the records of
     * silenced errors, so the other handlers don't receive more records than they would without us, consistently
     * with how Symfony is configured.
     */
    private function enableScreamMode(ContainerBuilder $container): void
    {
        /** @var array<string, mixed> $options */
        $options = $container->getDefinition('sentry.client.options')->getArgument(0);

        if (true !== $container->getParameterBag()->resolveValue($options['capture_silenced_errors'] ?? false)) {
            return;
        }

        $forcedScreamMode = false;

        // Symfony >= 6.4 configures its ErrorHandler with the ErrorHandlerConfigurator, older versions with the
        // DebugHandlersListener. Both have a "$scream" constructor argument.
        foreach (['debug.error_handler_configurator', 'debug.debug_handlers_listener'] as $id) {
            if (!$container->hasDefinition($id)) {
                continue;
            }

            $definition = $container->getDefinition($id);
            $position = $this->getConstructorArgumentPosition($container, $definition, 'scream');

            if (null === $position) {
                continue;
            }

            // FrameworkBundle sets it to "%kernel.debug%". When we cannot tell, we assume the application screams,
            // so we never hide records from the other handlers
            if (false !== $container->getParameterBag()->resolveValue($definition->getArgument($position))) {
                return;
            }

            $definition->replaceArgument($position, true);
            $forcedScreamMode = true;
        }

        if ($forcedScreamMode) {
            $container->getDefinition(SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID)->replaceArgument(2, false);
        }
    }

    /**
     * Returns the position of a constructor argument by its name, as long as the definition sets it. Positions differ
     * between Symfony versions.
     */
    private function getConstructorArgumentPosition(ContainerBuilder $container, Definition $definition, string $name): ?int
    {
        $class = $container->getParameterBag()->resolveValue($definition->getClass());

        if (!\is_string($class) || !class_exists($class) || null === $constructor = (new \ReflectionClass($class))->getConstructor()) {
            return null;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($name === $parameter->getName()) {
                return \array_key_exists($parameter->getPosition(), $definition->getArguments()) ? $parameter->getPosition() : null;
            }
        }

        return null;
    }
}
