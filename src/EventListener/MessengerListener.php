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
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class MessengerListener
{
    /**
     * Context objects are limited to 8kB.
     *
     * @see https://develop.sentry.dev/sdk/data-handling/#variable-size
     */
    private const MAX_CONTEXT_SIZE_IN_BYTES = 8192;

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
     * @var SerializerInterface|null The serializer used to encode the envelope
     */
    private $serializer;

    /**
     * @var bool Whether personally identifiable information should be added by default
     */
    private $sendDefaultPii;

    /**
     * @param HubInterface             $hub              The current hub
     * @param bool                     $captureSoftFails Whether to capture errors thrown
     *                                                   while processing a message that
     *                                                   will be retried
     * @param SerializerInterface|null $serializer       The serializer used to encode the envelope
     * @param bool                     $sendDefaultPii   Whether personally identifiable information should be added by
     *                                                   default
     */
    public function __construct(HubInterface $hub, bool $captureSoftFails = true, ?SerializerInterface $serializer = null, bool $sendDefaultPii = false)
    {
        $this->hub = $hub;
        $this->captureSoftFails = $captureSoftFails;
        $this->serializer = $serializer;
        $this->sendDefaultPii = $sendDefaultPii;
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

            if ($this->sendDefaultPii && null !== $this->serializer) {
                $value = $this->serializer->encode($event->getEnvelope());
                if ($this->isContextValueWithinSizeLimits($value)) {
                    $scope->setContext('messenger.envelope', $value);
                }
            }

            $this->captureException($exception, $event->willRetry());
        });

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
        if ($exception instanceof HandlerFailedException) {
            foreach ($exception->getNestedExceptions() as $nestedException) {
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

    private function isContextValueWithinSizeLimits(array $value): bool
    {
        return mb_strlen(serialize($value), '8bit') <= self::MAX_CONTEXT_SIZE_IN_BYTES;
    }
}
