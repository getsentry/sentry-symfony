<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
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
    public function testHandleWorkerMessageFailedEvent(array $exceptions, WorkerMessageFailedEvent $event, array $expectedTags, bool $expectedIsHandled): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $scope = new Scope();

        $this->hub->expects($this->once())
            ->method('withScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->hub->expects($this->exactly(\count($exceptions)))
            ->method('captureEvent')
            ->withConsecutive(...array_map(function (\Throwable $expectedException) use ($expectedIsHandled): array {
                return [
                    $this->anything(),
                    $this->logicalAnd(
                        $this->isInstanceOf(EventHint::class),
                        $this->callback(static function (EventHint $subject) use ($expectedException, $expectedIsHandled) {
                            self::assertSame($expectedException, $subject->exception);
                            self::assertNotNull($subject->mechanism);
                            self::assertSame($expectedIsHandled, $subject->mechanism->isHandled());

                            return true;
                        })
                    ),
                ];
            }, $exceptions));

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $listener = new MessengerListener($this->hub);
        $listener->handleWorkerMessageFailedEvent($event);

        $sentryEvent = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($sentryEvent);
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
            false,
        ];

        yield 'envelope.throwable INSTANCEOF DelayedMessageHandlingException' => [
            $exceptions,
            $this->getMessageFailedEvent($envelope, 'receiver', new DelayedMessageHandlingException($exceptions), false),
            [
                'messenger.receiver_name' => 'receiver',
                'messenger.message_class' => \get_class($envelope->getMessage()),
            ],
            false,
        ];

        yield 'envelope.throwable INSTANCEOF HandlerFailedException - RETRYING' => [
            $exceptions,
            $this->getMessageFailedEvent($envelope, 'receiver', new HandlerFailedException($envelope, $exceptions), true),
            [
                'messenger.receiver_name' => 'receiver',
                'messenger.message_class' => \get_class($envelope->getMessage()),
            ],
            true,
        ];

        yield 'envelope.throwable INSTANCEOF Exception' => [
            [$exceptions[0]],
            $this->getMessageFailedEvent($envelope, 'receiver', $exceptions[0], false),
            [
                'messenger.receiver_name' => 'receiver',
                'messenger.message_class' => \get_class($envelope->getMessage()),
            ],
            false,
        ];

        yield 'envelope.throwable INSTANCEOF Exception - RETRYING' => [
            [$exceptions[0]],
            $this->getMessageFailedEvent($envelope, 'receiver', $exceptions[0], true),
            [
                'messenger.receiver_name' => 'receiver',
                'messenger.message_class' => \get_class($envelope->getMessage()),
            ],
            true,
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
            false,
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

    public function testIsolateBreadcrumbsByMessagePushAndPopScopeWhenEnabled(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        // Received event should push
        $this->hub->expects($this->once())
            ->method('pushScope');

        // Handled event should pop
        $this->hub->expects($this->once())
            ->method('popScope');

        $listener = new MessengerListener($this->hub, true, true);

        $envelope = Envelope::wrap((object) []);
        $listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'receiver'));
        $listener->handleWorkerMessageHandledEvent(new WorkerMessageHandledEvent($envelope, 'receiver'));
    }

    public function testIsolateBreadcrumbsByMessageDoesNotPushOrPopWhenDisabled(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $this->hub->expects($this->never())
            ->method('pushScope');

        $this->hub->expects($this->never())
            ->method('popScope');

        $listener = new MessengerListener($this->hub, true, false);

        $envelope = Envelope::wrap((object) []);
        $listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'receiver'));
        $listener->handleWorkerMessageHandledEvent(new WorkerMessageHandledEvent($envelope, 'receiver'));
    }

    public function testIsolateBreadcrumbsByMessagePopsAfterFailureWhenEnabled(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $this->hub->expects($this->once())
            ->method('pushScope');

        $this->hub->expects($this->once())
            ->method('popScope');

        $this->hub->expects($this->once())
            ->method('withScope')
            ->willReturnCallback(static function (callable $callback): void {
                $callback(new Scope());
            });

        $this->hub->expects($this->any())
            ->method('getClient')
            ->willReturn($this->client);

        $listener = new MessengerListener($this->hub, true, true);
        $envelope = Envelope::wrap((object) []);

        $listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'receiver'));

        $event = $this->getMessageFailedEvent($envelope, 'receiver', new \Exception('boom'), false);
        $listener->handleWorkerMessageFailedEvent($event);
    }

    private function getMessageFailedEvent(Envelope $envelope, string $receiverName, \Throwable $error, bool $retry): WorkerMessageFailedEvent
    {
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
