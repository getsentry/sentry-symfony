<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionMechanism;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\WrappedExceptionsInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

final class MessengerListener
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var bool Whether to capture errors thrown while processing a message that
     *           will be retried
     */
    private $captureSoftFails;

    /**
     * @var bool When this is enabled, a new scope is pushed on the stack when a message
     *           is received and will pop it again after the message was finished (either success or fail).
     *           This allows us to have breadcrumbs only for one message and no breadcrumb is leaked into
     *           future messages.
     */
    private $isolateBreadcrumbsByMessage;

    /**
     * @param HubInterface $hub                         The current hub
     * @param bool         $captureSoftFails            Whether to capture errors thrown
     *                                                  while processing a message that
     *                                                  will be retried
     * @param bool         $isolateBreadcrumbsByMessage Whether to reset all breadcrumbs after a message
     */
    public function __construct(HubInterface $hub, bool $captureSoftFails = true, bool $isolateBreadcrumbsByMessage = false)
    {
        $this->hub = $hub;
        $this->captureSoftFails = $captureSoftFails;
        $this->isolateBreadcrumbsByMessage = $isolateBreadcrumbsByMessage;
    }

    /**
     * This method is called for each message that failed to be handled.
     *
     * @param WorkerMessageFailedEvent $event The event
     */
    public function handleWorkerMessageFailedEvent(WorkerMessageFailedEvent $event): void
    {
        try {
            if (!$this->captureSoftFails && $event->willRetry()) {
                return;
            }

            $this->hub->withScope(function (Scope $scope) use ($event): void {
                $envelope = $event->getEnvelope();
                $exception = $event->getThrowable();

                $scope->setTag('messenger.receiver_name', $event->getReceiverName());
                $scope->setTag('messenger.message_class', \get_class($envelope->getMessage()));

                /** @var BusNameStamp|null $messageBusStamp */
                $messageBusStamp = $envelope->last(BusNameStamp::class);

                if (null !== $messageBusStamp) {
                    $scope->setTag('messenger.message_bus', $messageBusStamp->getBusName());
                }

                $this->captureException($exception, $event->willRetry());
            });

            $this->flushClient();
        } finally {
            // We always want to pop the scope at the end of this method to add the breadcrumbs
            // to any potential event that is produced.
            if ($this->isolateBreadcrumbsByMessage) {
                $this->hub->popScope();
            }
        }
    }

    /**
     * This method is called for each handled message.
     *
     * @param WorkerMessageHandledEvent $event The event
     */
    public function handleWorkerMessageHandledEvent(WorkerMessageHandledEvent $event): void
    {
        // Flush normally happens at shutdown... which only happens in the worker if it is run with a lifecycle limit
        // such as --time=X or --limit=Y. Flush immediately in a background worker.
        $this->flushClient();
        if ($this->isolateBreadcrumbsByMessage) {
            $this->hub->popScope();
        }
    }

    /**
     * Method that will push a new scope on the hub to create message local breadcrumbs that will not
     * "leak" into future messages.
     *
     * @param WorkerMessageReceivedEvent $event
     *
     * @return void
     */
    public function handleWorkerMessageReceivedEvent(WorkerMessageReceivedEvent $event): void
    {
        if ($this->isolateBreadcrumbsByMessage) {
            $this->hub->pushScope();
        }
    }

    /**
     * Creates Sentry events from the given exception.
     *
     * Unpacks multiple exceptions wrapped in a HandlerFailedException and notifies
     * Sentry of each individual exception.
     *
     * If the message will be retried the exceptions will be marked as handled
     * in Sentry.
     */
    private function captureException(\Throwable $exception, bool $willRetry): void
    {
        if ($exception instanceof WrappedExceptionsInterface) {
            $exception = $exception->getWrappedExceptions();
        } elseif ($exception instanceof HandlerFailedException && method_exists($exception, 'getNestedExceptions')) {
            $exception = $exception->getNestedExceptions();
        } elseif ($exception instanceof DelayedMessageHandlingException && method_exists($exception, 'getExceptions')) {
            $exception = $exception->getExceptions();
        }

        if (\is_array($exception)) {
            foreach ($exception as $nestedException) {
                $this->captureException($nestedException, $willRetry);
            }

            return;
        }

        $hint = EventHint::fromArray([
            'exception' => $exception,
            'mechanism' => new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, $willRetry),
        ]);

        $this->hub->captureEvent(Event::createEvent(), $hint);
    }

    private function flushClient(): void
    {
        $client = $this->hub->getClient();

        if (null !== $client) {
            $client->flush();
        }
    }
}
