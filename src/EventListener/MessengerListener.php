<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\FlushableClientInterface;
use Sentry\State\HubInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

final class MessengerListener
{
    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var bool
     */
    private $captureSoftFails;

    /**
     * @param HubInterface $hub
     * @param bool         $captureSoftFails
     */
    public function __construct(HubInterface $hub, bool $captureSoftFails = true)
    {
        $this->hub = $hub;
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

        if ($error instanceof HandlerFailedException) {
            foreach ($error->getNestedExceptions() as $nestedException) {
                $this->hub->captureException($nestedException);
            }
        } else {
            $this->hub->captureException($error);
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
        $client = $this->hub->getClient();
        if ($client instanceof FlushableClientInterface) {
            $client->flush();
        }
    }
}
