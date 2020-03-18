<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\FlushableClientInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

class MessengerListener
{
    /**
     * @var FlushableClientInterface
     */
    private $client;

    /**
     * @param FlushableClientInterface $client
     */
    public function __construct(FlushableClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * @param WorkerMessageFailedEvent $event
     */
    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            // Only capture the hard fails. I.e. not those that will be scheduled for retry.
            return;
        }

        $error = $event->getThrowable();

        if ($error instanceof HandlerFailedException) {
            // Unwrap the messenger exception to get the original error
            $error = $error->getPrevious();
        }

        $this->client->captureException($error);
    }

    /**
     * @param WorkerMessageHandledEvent $event
     */
    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        // Flush normally happens at shutdown... which only happens in the worker if it is run with a lifecycle limit
        // such as --time=X or --limit=Y. Flush immediately in a background worker.
        $this->client->flush();
    }
}
