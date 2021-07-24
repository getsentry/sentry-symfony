<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\Tests\End2End\App\Kernel;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\BusNameStamp;

final class MessengerListenerTest extends TestCase
{
    /**
     * @var MockObject&ClientInterface
     */
    private $client;

    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
    }

    /**
     * @dataProvider handleWorkerMessageFailedEventDataProvider
     *
     * @param \Throwable[]          $exceptions
     * @param array<string, string> $expectedTags
     */
    public function testHandleWorkerMessageFailedEvent(array $exceptions, WorkerMessageFailedEvent $event, array $expectedTags): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $scope = new Scope();

        $this->hub->expects($this->once())
            ->method('withScope')
            ->willReturnCallback(function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->hub->expects($this->exactly(\count($exceptions)))
            ->method('captureException')
            ->withConsecutive(...array_map(static function (\Throwable $exception): array {
                return [$exception];
            }, $exceptions));

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $listener = new MessengerListener($this->hub);
        $listener->handleWorkerMessageFailedEvent($event);

        $sentryEvent = $scope->applyToEvent(Event::createEvent());

        $this->assertSame($expectedTags, $sentryEvent->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleWorkerMessageFailedEventDataProvider(): \Generator
    {
        if (!$this->supportsMessenger()) {
            return;
        }

        $envelope = Envelope::wrap((object) []);
        $exceptions = [
            new \Exception(),
            new \Exception(),
        ];

        yield 'envelope.throwable INSTANCEOF HandlerFailedException' => [
            $exceptions,
            $this->getMessageFailedEvent($envelope, 'receiver', new HandlerFailedException($envelope, $exceptions), false),
            [
                'messenger.receiver_name' => 'receiver',
                'messenger.message_class' => \get_class($envelope->getMessage()),
            ],
        ];

        yield 'envelope.throwable INSTANCEOF Exception' => [
            [$exceptions[0]],
            $this->getMessageFailedEvent($envelope, 'receiver', $exceptions[0], false),
            [
                'messenger.receiver_name' => 'receiver',
                'messenger.message_class' => \get_class($envelope->getMessage()),
            ],
        ];

        $envelope = new Envelope((object) [], [new BusNameStamp('bus.foo')]);

        yield 'envelope.stamps CONTAINS BusNameStamp' => [
            [$exceptions[0]],
            $this->getMessageFailedEvent($envelope, 'receiver', $exceptions[0], false),
            [
                'messenger.receiver_name' => 'receiver',
                'messenger.message_class' => \get_class($envelope->getMessage()),
                'messenger.message_bus' => 'bus.foo',
            ],
        ];
    }

    /**
     * @dataProvider handleWorkerMessageFailedEventWithCaptureSoftFailsFlagDataProvider
     */
    public function testHandleWorkerMessageFailedEventWithCaptureSoftFailsFlag(bool $captureSoftFails, bool $retry, bool $shouldCallFlush): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $envelope = Envelope::wrap((object) []);
        $event = $this->getMessageFailedEvent($envelope, 'receiver', new \Exception(), $retry);

        $this->hub->expects($this->any())
            ->method('getClient')
            ->willReturn($this->client);

        $this->client->expects($shouldCallFlush ? $this->once() : $this->never())
            ->method('flush');

        $listener = new MessengerListener($this->hub, $captureSoftFails);
        $listener->handleWorkerMessageFailedEvent($event);
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleWorkerMessageFailedEventWithCaptureSoftFailsFlagDataProvider(): \Generator
    {
        yield '$captureSoftFails = FALSE && $willRetry = TRUE => KO' => [
            false,
            true,
            false,
        ];

        yield '$captureSoftFails = FALSE && $willRetry = FALSE => OK' => [
            false,
            false,
            true,
        ];

        yield '$captureSoftFails = TRUE && $willRetry = TRUE => OK' => [
            false,
            false,
            true,
        ];

        yield '$captureSoftFails = TRUE && $willRetry = FALSE => OK' => [
            false,
            false,
            true,
        ];
    }

    public function testHandleWorkerMessageHandledEvent(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $this->client->expects($this->once())
            ->method('flush');

        $listener = new MessengerListener($this->hub);
        $listener->handleWorkerMessageHandledEvent(new WorkerMessageHandledEvent(Envelope::wrap((object) []), 'receiver'));
    }

    private function getMessageFailedEvent(Envelope $envelope, string $receiverName, \Throwable $error, bool $retry): WorkerMessageFailedEvent
    {
        if (version_compare(Kernel::VERSION, '4.4.0', '<')) {
            return new WorkerMessageFailedEvent($envelope, $receiverName, $error, $retry);
        }

        $event = new WorkerMessageFailedEvent($envelope, $receiverName, $error);

        if ($retry) {
            $event->setForRetry();
        }

        return $event;
    }

    private function supportsMessenger(): bool
    {
        return interface_exists(MessageBusInterface::class);
    }
}
