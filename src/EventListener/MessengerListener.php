<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\Severity;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
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

    /**
     * This method is called for each message that failed to be handled.
     *
     * @param WorkerMessageFailedEvent $event The event
     */
    public function handleWorkerMessageFailedEvent(WorkerMessageFailedEvent $event): void
    {
        if (!$this->captureSoftFails && $event->willRetry()) {
            return;
        }

        $error = $event->getThrowable();
        if ($error instanceof HandlerFailedException) {
            foreach ($error->getNestedExceptions() as $nestedException) {
                $this->captureException(
                    $nestedException,
                    $event->getEnvelope(),
                    $event->getReceiverName()
                );
            }
        } else {
            $this->captureException(
                $error,
                $event->getEnvelope(),
                $event->getReceiverName()
            );
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

    private function captureException(
        \Throwable $exception,
        Envelope $envelope,
        string $receiverName
    ): void {
        $event = Event::createEvent();
        $event->setLevel(Severity::error());
        $exceptionHint = EventHint::fromArray([
            'exception' => $exception,
        ]);

        $this->hub->withScope(function (Scope $scope) use ($receiverName, $envelope, $event, $exceptionHint) {
            $tags = [
                'messenger.receiver_name' => $receiverName,
                'messenger.message_class' => \get_class($envelope->getMessage()),
            ];

            /**
             * @var BusNameStamp|null $messageBusStamp
             */
            $messageBusStamp = $envelope->last(BusNameStamp::class);
            if (null !== $messageBusStamp) {
                $tags['messenger.message_bus'] = $messageBusStamp->getBusName();
            }

            $scope->setTags($tags);
            $this->hub->captureEvent($event, $exceptionHint);
        });
    }
}
