<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Event;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
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
     * @var bool Keeps the state relative of a scope push
     */
    private $scopePushed = false;

    /**
     * @param HubInterface $hub              The current hub
     * @param bool         $captureSoftFails Whether to capture errors thrown
     *                                       while processing a message that
     *                                       will be retried
     */
    public function __construct(HubInterface $hub, bool $captureSoftFails = true)
    {
        $this->hub = $hub;
        $this->captureSoftFails = $captureSoftFails;
    }

    public function handleWorkerMessageReceivedEvent(WorkerMessageReceivedEvent $event): void
    {
        $this->hub->pushScope();
        $this->scopePushed = true;
    }

    /**
     * This method is called for each message that failed to be handled.
     *
     * @param WorkerMessageFailedEvent $event The event
     */
    public function handleWorkerMessageFailedEvent(WorkerMessageFailedEvent $event): void
    {
        file_put_contents('./log.log', "\nhandleWorkerMessageFailedEvent\n", \FILE_APPEND);

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

            if ($exception instanceof HandlerFailedException) {
                foreach ($exception->getNestedExceptions() as $nestedException) {
                    $this->hub->captureException($nestedException);
                }
            } else {
                $this->hub->captureException($exception);
            }
        });

        if ($this->scopePushed) {
            $this->hub->popScope();
        }

        $this->flushClient();
    }

    /**
     * This method is called for each handled message.
     *
     * @param WorkerMessageHandledEvent $event The event
     */
    public function handleWorkerMessageHandledEvent(WorkerMessageHandledEvent $event): void
    {
        file_put_contents('./log.log', "\nhandleWorkerMessageHandledEvent\n", \FILE_APPEND);

        if ($this->scopePushed) {
            $this->hub->popScope();
        }

        // Flush normally happens at shutdown... which only happens in the worker if it is run with a lifecycle limit
        // such as --time=X or --limit=Y. Flush immediately in a background worker.
        $this->flushClient();
    }

    private function flushClient(): void
    {
        $client = $this->hub->getClient();

        if (null !== $client) {
            $client->flush();
        }
    }
}
