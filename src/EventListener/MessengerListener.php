<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentrySdk;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class MessengerListener
{
    /**
     * @var bool
     */
    private $captureSoftFails;

    /**
     * @param bool $captureSoftFails
     */
    public function __construct(bool $captureSoftFails = true)
    {
        $this->captureSoftFails = $captureSoftFails;
    }

    /**
     * @param WorkerMessageFailedEvent $event
     */
    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if (! $this->captureSoftFails && $event->willRetry()) {
            // Don't capture soft fails. I.e. those that will be scheduled for retry.
            return;
        }

        $error = $event->getThrowable();

        if ($error instanceof HandlerFailedException && null !== $error->getPrevious()) {
            // Unwrap the messenger exception to get the original error
            $error = $error->getPrevious();
        }

        $hub = SentrySdk::getCurrentHub();
        $hub->captureException($error);
        if (method_exists($hub->getClient(), 'flush')) {
            $hub->getClient()->flush();
        }
    }

    /**
     * @param WorkerMessageHandledEvent $event
     */
    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        // Flush normally happens at shutdown... which only happens in the worker if it is run with a lifecycle limit
        // such as --time=X or --limit=Y. Flush immediately in a background worker.
        $hub = SentrySdk::getCurrentHub();
        if (method_exists($hub->getClient(), 'flush')) {
            $hub->getClient()->flush();
        }
    }
}
