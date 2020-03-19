<?php declare(strict_types=1);

namespace Sentry\SentryBundle\Test\EventListener;

use Sentry\FlushableClientInterface;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\Test\BaseTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

class MessengerListenerTest extends BaseTestCase
{
    private $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = $this->prophesize(FlushableClientInterface::class);
    }

    public function testSoftFailsAreRecorded(): void
    {
        $error = new \RuntimeException();

        $this->client->captureException($error)->shouldBeCalledOnce();
        $this->client->flush()->shouldBeCalledOnce();

        $listener = new MessengerListener($this->client->reveal(), true);
        $message  = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event    = new WorkerMessageFailedEvent($envelope, 'receiver', $error);
        $event->setForRetry();

        $listener->onWorkerMessageFailed($event);
    }

    public function testHardFailsAreRecorded(): void
    {
        $error = new \RuntimeException();

        $this->client->captureException($error)->shouldBeCalledOnce();
        $this->client->flush()->shouldBeCalledOnce();

        $listener = new MessengerListener($this->client->reveal(), true);
        $message  = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event    = new WorkerMessageFailedEvent($envelope, 'receiver', $error);

        $listener->onWorkerMessageFailed($event);
    }

    public function testSoftFailsAreNotRecorded(): void
    {
        $error = new \RuntimeException();

        $this->client->captureException($error)->shouldNotBeCalled();
        $this->client->flush()->shouldNotBeCalled();

        $listener = new MessengerListener($this->client->reveal(), false);
        $message  = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event    = new WorkerMessageFailedEvent($envelope, 'receiver', $error);
        $event->setForRetry();

        $listener->onWorkerMessageFailed($event);
    }

    public function testHardFailsAreRecordedWithCaptureSoftDisabled(): void
    {
        $error = new \RuntimeException();

        $this->client->captureException($error)->shouldBeCalledOnce();
        $this->client->flush()->shouldBeCalledOnce();

        $listener = new MessengerListener($this->client->reveal(), false);
        $message  = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event    = new WorkerMessageFailedEvent($envelope, 'receiver', $error);

        $listener->onWorkerMessageFailed($event);
    }

    public function testHandlerFailedExceptionIsUnwrapped(): void
    {
        $message      = (object) ['foo' => 'bar'];
        $envelope     = Envelope::wrap($message);
        $error        = new \RuntimeException();
        $wrappedError = new HandlerFailedException($envelope, [$error]);

        $event = new WorkerMessageFailedEvent($envelope, 'receiver', $wrappedError);

        $this->client->captureException($error)->shouldBeCalledOnce();
        $this->client->flush()->shouldBeCalledOnce();

        $listener = new MessengerListener($this->client->reveal());
        $listener->onWorkerMessageFailed($event);
    }

    public function testClientIsFlushedWhenMessageHandled(): void
    {
        $this->client->flush()->shouldBeCalledOnce();
        $listener = new MessengerListener($this->client->reveal());

        $message  = (object) ['foo' => 'bar'];
        $envelope = Envelope::wrap($message);
        $event    = new WorkerMessageHandledEvent($envelope, 'receiver');

        $listener->onWorkerMessageHandled($event);
    }
}
