<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\Compiler\MonologErrorReportingPass;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\Monolog\CapturedExceptionTracker;
use Sentry\SentryBundle\Monolog\PhpErrorHandler;
use Sentry\SentryBundle\Monolog\UncaughtExceptionHandler;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Debug\ErrorHandlerConfigurator;
use Symfony\Component\HttpKernel\EventListener\DebugHandlersListener;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;

final class MonologErrorReportingPassTest extends TestCase
{
    public function testHandlersArePushedToTheExistingErrorChannelsOnly(): void
    {
        $container = $this->createContainer(['app', 'request', 'console', 'php', 'doctrine']);

        (new MonologErrorReportingPass())->process($container);

        $this->assertSame([SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID], $this->getPushedHandlers($container, 'php'));
        $this->assertSame([SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID], $this->getPushedHandlers($container, 'request'));
        $this->assertSame([SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID], $this->getPushedHandlers($container, 'console'));
        $this->assertSame([], $this->getPushedHandlers($container, 'app'));
        $this->assertSame([], $this->getPushedHandlers($container, 'doctrine'));
    }

    public function testHandlersArePushedAfterTheHandlersOfTheApplication(): void
    {
        $container = $this->createContainer(['request']);
        $container->getDefinition('monolog.logger.request')->addMethodCall('pushHandler', [new Reference('monolog.handler.main')]);

        (new MonologErrorReportingPass())->process($container);

        $this->assertSame(['monolog.handler.main', SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID], $this->getPushedHandlers($container, 'request'));
    }

    public function testUnusedServicesAreRemovedWithoutMonologBundle(): void
    {
        $container = $this->createContainer([]);

        (new MonologErrorReportingPass())->process($container);

        $this->assertFalse($container->hasDefinition(SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID));
        $this->assertFalse($container->hasDefinition(SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID));
        $this->assertFalse($container->hasDefinition(SentryExtension::MONOLOG_CAPTURED_EXCEPTION_TRACKER_SERVICE_ID));
    }

    public function testUnusedHandlerIsRemovedWithoutItsChannels(): void
    {
        $container = $this->createContainer(['request']);

        (new MonologErrorReportingPass())->process($container);

        $this->assertFalse($container->hasDefinition(SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID));
        $this->assertTrue($container->hasDefinition(SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID));
        $this->assertTrue($container->hasDefinition(SentryExtension::MONOLOG_CAPTURED_EXCEPTION_TRACKER_SERVICE_ID));
    }

    public function testNothingHappensWhenTheHandlersAreNotRegistered(): void
    {
        $container = $this->createContainer(['request', 'php']);
        $container->removeDefinition(SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID);
        $container->removeDefinition(SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID);

        (new MonologErrorReportingPass())->process($container);

        $this->assertSame([], $this->getPushedHandlers($container, 'request'));
        $this->assertSame([], $this->getPushedHandlers($container, 'php'));
    }

    public function testUncaughtExceptionHandlerIsPushedToCustomExceptionLogChannels(): void
    {
        if (!$this->hasConstructorArgument(ErrorListener::class, 'loggers')) {
            $this->markTestSkipped('The "framework.exceptions.*.log_channel" option requires Symfony >= 7.3.');
        }

        $container = $this->createContainer(['request', 'errors', 'php']);
        $container->setDefinition('exception_listener', $this->createDefinition(ErrorListener::class, [
            'loggers' => ['errors' => new Reference('monolog.logger.errors'), 'php' => new Reference('monolog.logger.php')],
        ]));

        (new MonologErrorReportingPass())->process($container);

        $this->assertSame([SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID], $this->getPushedHandlers($container, 'errors'));
        // The PHP error channels are left to their own handler
        $this->assertSame([SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID], $this->getPushedHandlers($container, 'php'));
    }

    public function testScreamModeIsForcedAndSilencedErrorsStopWhenTheApplicationDoesNotScream(): void
    {
        $container = $this->createContainer(['php'], false);

        (new MonologErrorReportingPass())->process($container);

        $this->assertTrue($this->getScreamArgument($container));
        $this->assertFalse($container->getDefinition(SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID)->getArgument(2));
    }

    public function testScreamModeIsLeftAloneWhenTheApplicationScreams(): void
    {
        $container = $this->createContainer(['php'], true);

        (new MonologErrorReportingPass())->process($container);

        $this->assertSame('%kernel.debug%', $this->getScreamArgument($container));
        $this->assertTrue($container->getDefinition(SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID)->getArgument(2));
    }

    public function testScreamModeIsLeftAloneWhenSilencedErrorsAreNotCaptured(): void
    {
        $container = $this->createContainer(['php'], false, false);

        (new MonologErrorReportingPass())->process($container);

        $this->assertSame('%kernel.debug%', $this->getScreamArgument($container));
        $this->assertTrue($container->getDefinition(SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID)->getArgument(2));
    }

    public function testScreamModeIsLeftAloneWithoutThePhpErrorChannel(): void
    {
        $container = $this->createContainer(['request'], false);

        (new MonologErrorReportingPass())->process($container);

        $this->assertSame('%kernel.debug%', $this->getScreamArgument($container));
    }

    /**
     * @param string[] $channels The channels MonologBundle created a logger for
     */
    private function createContainer(array $channels, bool $debug = false, bool $captureSilencedErrors = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setDefinition('sentry.client.options', new Definition(Options::class, [['capture_silenced_errors' => $captureSilencedErrors]]));

        // As registered by SentryExtension
        $tracker = new Reference(SentryExtension::MONOLOG_CAPTURED_EXCEPTION_TRACKER_SERVICE_ID);
        $container->setDefinition(SentryExtension::MONOLOG_CAPTURED_EXCEPTION_TRACKER_SERVICE_ID, new Definition(CapturedExceptionTracker::class));
        $container->setDefinition(SentryExtension::MONOLOG_PHP_ERROR_HANDLER_SERVICE_ID, new Definition(PhpErrorHandler::class, [new Reference(HubInterface::class), $tracker, true]));
        $container->setDefinition(SentryExtension::MONOLOG_UNCAUGHT_EXCEPTION_HANDLER_SERVICE_ID, new Definition(UncaughtExceptionHandler::class, [new Reference(HubInterface::class), $tracker, true]));

        // As registered by MonologBundle
        foreach ($channels as $channel) {
            $container->setDefinition('app' === $channel ? 'monolog.logger' : 'monolog.logger.' . $channel, new Definition(Logger::class, [$channel]));
        }

        // As registered by FrameworkBundle, depending on the version of Symfony
        if (class_exists(ErrorHandlerConfigurator::class)) {
            $container->setDefinition('debug.error_handler_configurator', $this->createDefinition(ErrorHandlerConfigurator::class, ['scream' => '%kernel.debug%']));
        } else {
            $container->setDefinition('debug.debug_handlers_listener', $this->createDefinition(DebugHandlersListener::class, ['scream' => '%kernel.debug%']));
        }

        return $container;
    }

    /**
     * Creates a definition setting all the arguments of the constructor, whose positions differ between versions.
     *
     * @param class-string         $class
     * @param array<string, mixed> $arguments
     */
    private function createDefinition(string $class, array $arguments): Definition
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        $this->assertNotNull($constructor);

        $definition = new Definition($class);

        foreach ($constructor->getParameters() as $parameter) {
            $definition->setArgument($parameter->getPosition(), $arguments[$parameter->getName()] ?? null);
        }

        return $definition;
    }

    /**
     * @param class-string $class
     */
    private function hasConstructorArgument(string $class, string $name): bool
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();

        foreach (null !== $constructor ? $constructor->getParameters() : [] as $parameter) {
            if ($name === $parameter->getName()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return mixed
     */
    private function getScreamArgument(ContainerBuilder $container)
    {
        $id = class_exists(ErrorHandlerConfigurator::class) ? 'debug.error_handler_configurator' : 'debug.debug_handlers_listener';
        $definition = $container->getDefinition($id);
        $class = $definition->getClass();
        $this->assertTrue(null !== $class && class_exists($class));

        $constructor = (new \ReflectionClass($class))->getConstructor();
        $this->assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            if ('scream' === $parameter->getName()) {
                return $definition->getArgument($parameter->getPosition());
            }
        }

        $this->fail(\sprintf('The "%s" service has no "$scream" argument.', $id));
    }

    /**
     * @return string[]
     */
    private function getPushedHandlers(ContainerBuilder $container, string $channel): array
    {
        $handlers = [];

        foreach ($container->getDefinition('app' === $channel ? 'monolog.logger' : 'monolog.logger.' . $channel)->getMethodCalls() as [$method, $arguments]) {
            if ('pushHandler' === $method) {
                $handlers[] = (string) $arguments[0];
            }
        }

        return $handlers;
    }
}
