<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\FlushableClientInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class MessengerListener
{
    /**
     * @var FlushableClientInterface
     */
    private $client;

    /**
     * @var bool
     */
    private $captureSoftFails;

    /**
     * @param FlushableClientInterface $client
     * @param bool                     $captureSoftFails
     */
    public function __construct(FlushableClientInterface $client, bool $captureSoftFails = true)
    {
        $this->client = $client;
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

        if (! $error instanceof HandlerFailedException) {
            // All errors thrown during handling a command are captured by the HandleMessageMiddleware,
            // and are raised inside a HandlerFailedException. Type check for safety.
            return;
        }

        foreach ($error->getNestedExceptions() as $nestedException) {
            $this->client->captureException($nestedException);
        }

        $this->flush();
    }

    /**
     * @param WorkerMessageHandledEvent $event
     */
    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        // Flush normally happens at shutdown... which only happens in the worker if it is run with a lifecycle limit
        // such as --time=X or --limit=Y. Flush immediately in a background worker.
        $this->flush();
    }

    private function flush(): void
    {
        $this->client->flush();
    }
}
