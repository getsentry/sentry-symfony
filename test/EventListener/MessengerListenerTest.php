<?php

namespace Sentry\SentryBundle\Test\EventListener;

use Sentry\FlushableClientInterface;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\Test\BaseTestCase;
use Sentry\State\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

class MessengerListenerTest extends BaseTestCase
{
    private $client;
    private $hub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->prophesize(FlushableClientInterface::class);
        $this->hub = $this->prophesize(HubInterface::class);
        $this->hub->getClient()->willReturn($this->client);
    }

    public function testSoftFailsAreRecorded(): void
    {
        if (! $this->supportsMessenger()) {
            self::markTestSkipped('Messenger not supported in this environment.');
        }

        $error = new \RuntimeException();

        $this->hub->captureException($error)->shouldBeCalled();
        $this->client->flush()->shouldBeCalled();

        $listener = new MessengerListener($this->hub->reveal(), true);
        $message = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event = $this->getMessageFailedEvent($envelope, 'receiver', $error, true);

        $listener->onWorkerMessageFailed($event);
    }

    public function testHardFailsAreRecorded(): void
    {
        if (! $this->supportsMessenger()) {
            self::markTestSkipped('Messenger not supported in this environment.');
        }

        $error = new \RuntimeException();

        $this->hub->captureException($error)->shouldBeCalled();
        $this->client->flush()->shouldBeCalled();

        $listener = new MessengerListener($this->hub->reveal(), true);
        $message = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event = $this->getMessageFailedEvent($envelope, 'receiver', $error, false);

        $listener->onWorkerMessageFailed($event);
    }

    public function testSoftFailsAreNotRecorded(): void
    {
        if (! $this->supportsMessenger()) {
            self::markTestSkipped('Messenger not supported in this environment.');
        }

        $error = new \RuntimeException();

        $this->hub->captureException($error)->shouldNotBeCalled();
        $this->client->flush()->shouldNotBeCalled();

        $listener = new MessengerListener($this->hub->reveal(), false);
        $message = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event = $this->getMessageFailedEvent($envelope, 'receiver', $error, true);

        $listener->onWorkerMessageFailed($event);
    }

    public function testHardFailsAreRecordedWithCaptureSoftDisabled(): void
    {
        if (! $this->supportsMessenger()) {
            self::markTestSkipped('Messenger not supported in this environment.');
        }

        $error = new \RuntimeException();

        $this->hub->captureException($error)->shouldBeCalled();
        $this->client->flush()->shouldBeCalled();

        $listener = new MessengerListener($this->hub->reveal(), false);
        $message = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event = $this->getMessageFailedEvent($envelope, 'receiver', $error, false);

        $listener->onWorkerMessageFailed($event);
    }

    public function testHandlerFailedExceptionIsUnwrapped(): void
    {
        if (! $this->supportsMessenger()) {
            self::markTestSkipped('Messenger not supported in this environment.');
        }

        $message = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $error = new \RuntimeException();
        $wrappedError = new HandlerFailedException($envelope, [$error]);

        $event = $this->getMessageFailedEvent($envelope, 'receiver', $wrappedError, false);

        $this->hub->captureException($error)->shouldBeCalled();
        $this->client->flush()->shouldBeCalled();

        $listener = new MessengerListener($this->hub->reveal());
        $listener->onWorkerMessageFailed($event);
    }

    public function testClientIsFlushedWhenMessageHandled(): void
    {
        if (! $this->supportsMessenger()) {
            self::markTestSkipped('Messenger not supported in this environment.');
        }

        $this->client->flush()->shouldBeCalled();
        $listener = new MessengerListener($this->hub->reveal());

        $message = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event = new WorkerMessageHandledEvent($envelope, 'receiver', false);

        $listener->onWorkerMessageHandled($event);
    }

    /**
     * Messenger 4.4 and above removed the fourth constructor argument for whether the message will be retried.
     * Instead, the setForRetry setter method is used.
     *
     * @param Envelope   $envelope
     * @param string     $receiverName
     * @param \Throwable $error
     * @param bool       $retry
     *
     * @return WorkerMessageFailedEvent
     */
    private function getMessageFailedEvent(Envelope $envelope, string $receiverName, \Throwable $error, bool $retry): WorkerMessageFailedEvent
    {
        $event = new WorkerMessageFailedEvent($envelope, $receiverName, $error, $retry);

        if ($retry && method_exists($event, 'setForRetry')) {
            // Messenger 4.4 and above use this setter instead of the constructor argument.
            $event->setForRetry();
        }

        return $event;
    }

    private function supportsMessenger(): bool
    {
        return interface_exists(MessageBusInterface::class);
    }
}
