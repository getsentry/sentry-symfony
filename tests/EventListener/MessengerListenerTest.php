<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\SentryManager;
use Sentry\SentrySdk;
use Sentry\State\GlobalScope;
use Sentry\State\IsolationScope;
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
     * @var MockObject&SentryManager
     */
    private $sentry;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
        $this->sentry = $this->createMock(SentryManager::class);
    }

    protected function tearDown(): void
    {
        SentrySdk::endContext();
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

        $scope = new IsolationScope();

        $this->sentry->expects($this->once())
            ->method('withScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->sentry->expects($this->exactly(\count($exceptions)))
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

        $this->sentry->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $this->client->expects($this->once())
            ->method('flush');

        $listener = new MessengerListener($this->sentry);
        $listener->handleWorkerMessageFailedEvent($event);

        $sentryEvent = (new GlobalScope())->merge($scope)->applyToEvent(Event::createEvent());

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
    public function testHandleWorkerMessageFailedEventWithCaptureSoftFailsFlag(bool $captureSoftFails, bool $retry, bool $shouldCapture): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $envelope = Envelope::wrap((object) []);
        $event = $this->getMessageFailedEvent($envelope, 'receiver', new \Exception(), $retry);

        if ($shouldCapture) {
            $this->sentry->expects($this->once())
                ->method('withScope')
                ->willReturnCallback(static function (callable $callback): void {
                    $callback(new IsolationScope());
                });

            $this->sentry->expects($this->once())
                ->method('captureEvent')
                ->with(
                    $this->isInstanceOf(Event::class),
                    $this->isInstanceOf(EventHint::class)
                );

            $this->sentry->expects($this->once())
                ->method('getClient')
                ->willReturn($this->client);

            $this->client->expects($this->once())
                ->method('flush');
        } else {
            $this->sentry->expects($this->never())
                ->method('withScope');

            $this->sentry->expects($this->never())
                ->method('captureEvent');
            $this->sentry->expects($this->never())
                ->method('getClient');
            $this->client->expects($this->never())
                ->method('flush');
        }

        $listener = new MessengerListener($this->sentry, $captureSoftFails);
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
            true,
            true,
            true,
        ];

        yield '$captureSoftFails = TRUE && $willRetry = FALSE => OK' => [
            true,
            false,
            true,
        ];
    }

    public function testHandleWorkerMessageHandledEvent(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $this->sentry->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $this->client->expects($this->once())
            ->method('flush');

        $listener = new MessengerListener($this->sentry);
        $listener->handleWorkerMessageHandledEvent(new WorkerMessageHandledEvent(Envelope::wrap((object) []), 'receiver'));
    }

    public function testIsolateBreadcrumbsByMessagePushAndPopScopeWhenEnabled(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        // Received event should push
        $this->sentry->expects($this->once())
            ->method('pushScope');

        // Handled event should pop
        $this->sentry->expects($this->once())
            ->method('popScope');

        $this->sentry->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $this->client->expects($this->once())
            ->method('flush');

        $listener = new MessengerListener($this->sentry, true, true);

        $envelope = Envelope::wrap((object) []);
        $listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'receiver'));
        $listener->handleWorkerMessageHandledEvent(new WorkerMessageHandledEvent($envelope, 'receiver'));
    }

    public function testIsolateBreadcrumbsByMessageDoesNotPushOrPopWhenDisabled(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $this->sentry->expects($this->never())
            ->method('pushScope');

        $this->sentry->expects($this->never())
            ->method('popScope');

        $this->sentry->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $this->client->expects($this->once())
            ->method('flush');

        $listener = new MessengerListener($this->sentry, true, false);

        $envelope = Envelope::wrap((object) []);
        $listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'receiver'));
        $listener->handleWorkerMessageHandledEvent(new WorkerMessageHandledEvent($envelope, 'receiver'));
    }

    public function testIsolateBreadcrumbsByMessagePopsAfterFailureWhenEnabled(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $this->sentry->expects($this->once())
            ->method('pushScope');

        $this->sentry->expects($this->once())
            ->method('popScope');

        $this->sentry->expects($this->once())
            ->method('withScope')
            ->willReturnCallback(static function (callable $callback): void {
                $callback(new IsolationScope());
            });

        $this->sentry->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $this->client->expects($this->once())
            ->method('flush');

        $listener = new MessengerListener($this->sentry, true, true);
        $envelope = Envelope::wrap((object) []);

        $listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'receiver'));

        $event = $this->getMessageFailedEvent($envelope, 'receiver', new \Exception('boom'), false);
        $listener->handleWorkerMessageFailedEvent($event);
    }

    public function testRuntimeContextIsNotStartedWhenIsolationIsDisabled(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        SentrySdk::init();
        $globalContext = SentrySdk::getCurrentRuntimeContext();
        $listener = new MessengerListener($this->sentry, true, false, false);
        $envelope = Envelope::wrap((object) []);

        $listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'receiver'));

        $this->assertSame($globalContext, SentrySdk::getCurrentRuntimeContext());
    }

    public function testRuntimeContextIsStartedAndEndedForHandledMessage(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        SentrySdk::init();
        $globalContext = SentrySdk::getCurrentRuntimeContext();
        $listener = new MessengerListener(new SentryManager(), true, false, true);
        $envelope = Envelope::wrap((object) []);

        $listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'receiver'));

        $this->assertNotSame($globalContext, SentrySdk::getCurrentRuntimeContext());

        $listener->handleWorkerMessageHandledEvent(new WorkerMessageHandledEvent($envelope, 'receiver'));

        $this->assertSame($globalContext, SentrySdk::getCurrentRuntimeContext());
    }

    public function testRuntimeContextIsEndedForFailedMessageEvenIfSoftFailIsSkipped(): void
    {
        if (!$this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        SentrySdk::init();
        $globalContext = SentrySdk::getCurrentRuntimeContext();
        $listener = new MessengerListener(new SentryManager(), false, false, true);
        $envelope = Envelope::wrap((object) []);

        $listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'receiver'));

        $this->assertNotSame($globalContext, SentrySdk::getCurrentRuntimeContext());

        $event = $this->getMessageFailedEvent($envelope, 'receiver', new \Exception('retrying'), true);
        $listener->handleWorkerMessageFailedEvent($event);

        $this->assertSame($globalContext, SentrySdk::getCurrentRuntimeContext());
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
