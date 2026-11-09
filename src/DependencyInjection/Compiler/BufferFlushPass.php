<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Monolog\Handler\BufferHandler;
use Sentry\Monolog\ExceptionToSentryIssueHandler;
use Sentry\Monolog\LogToSentryIssueHandler;
use Sentry\SentryBundle\EventListener\BufferFlusher;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class BufferFlushPass implements CompilerPassInterface
{
    /**
     * The handlers that capture the records as Sentry issues, using the data of the current scope.
     */
    private const SENTRY_HANDLER_CLASSES = [
        LogToSentryIssueHandler::class,
        ExceptionToSentryIssueHandler::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        $sentryBufferHandlers = $this->findSentryBufferHandlers($container);

        if (empty($sentryBufferHandlers)) {
            return;
        }

        $flusherDefinition = new Definition(BufferFlusher::class);
        $flusherDefinition->setArguments([$sentryBufferHandlers]);
        $flusherDefinition->addTag('kernel.event_subscriber');

        $container->setDefinition('sentry.buffer_flusher', $flusherDefinition);
    }

    /**
     * Finds all {@link BufferHandler} that wrap a Sentry issue handler and register a service
     * that will flush them on KernelEvents::TERMINATE to make sure that all events retain
     * breadcrumbs and context information.
     *
     * @return Reference[]
     */
    private function findSentryBufferHandlers(ContainerBuilder $container): array
    {
        $sentryBufferHandlers = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            if (BufferHandler::class === $definition->getClass()) {
                $arguments = $definition->getArguments();
                if (!empty($arguments)) {
                    // The first argument of BufferHandler is the HandlerInterface, which
                    // can be a Sentry issue handler. Missing argument will be ignored.
                    $firstArgument = $arguments[0] ?? $arguments['$handler'] ?? null;

                    if ($firstArgument instanceof Reference) {
                        $referencedServiceId = (string) $firstArgument;
                        try {
                            $referencedDefinition = $container->findDefinition($referencedServiceId);

                            if (\in_array($referencedDefinition->getClass(), self::SENTRY_HANDLER_CLASSES, true)) {
                                $sentryBufferHandlers[] = new Reference($serviceId);
                            }
                        } catch (\Exception $e) {
                            // If the service from the first argument doesn't exist we just keep going
                            continue;
                        }
                    }
                }
            }
        }

        return $sentryBufferHandlers;
    }
}
