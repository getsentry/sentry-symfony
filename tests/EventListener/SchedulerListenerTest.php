<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sentry\CheckInStatus;
use Sentry\ClientInterface;
use Sentry\MonitorConfig;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\SchedulerListener;
use Sentry\SentryBundle\Tests\EventListener\Fixtures\SendDailyReportMessage;
use Sentry\State\HubInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\EventListener\DispatchSchedulerEventListener;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Generator\MessageGenerator;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\CallbackMessageProvider;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;

final class SchedulerListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var MockObject&LoggerInterface
     */
    private $logger;

    /**
     * @var list<array{slug: string, status: CheckInStatus, duration: mixed, monitor_config: MonitorConfig|null, check_in_id: string|null}>
     */
    private $checkIns = [];

    /**
     * @var SchedulerListener|null
     */
    private $listener;

    /**
     * @var EventDispatcher
     */
    private $dispatcher;

    /**
     * @var Schedule
     */
    private $schedule;

    protected function setUp(): void
    {
        if (!class_exists(PreRunEvent::class)) {
            $this->markTestSkipped('This test requires the "symfony/scheduler" Composer package (6.4 or newer) to be installed.');
        }

        $this->checkIns = [];
        $this->logger = $this->createMock(LoggerInterface::class);

        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturn(new Options(['logger' => $this->logger]));

        $this->hub = $this->createMock(HubInterface::class);
        $this->hub->method('getClient')->willReturn($client);
        $this->hub->method('captureCheckIn')
            ->willReturnCallback(function (string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): string {
                $this->checkIns[] = [
                    'slug' => $slug,
                    'status' => $status,
                    'duration' => $duration,
                    'monitor_config' => $monitorConfig,
                    'check_in_id' => $checkInId,
                ];

                return $checkInId ?? 'check-in-id-' . \count($this->checkIns);
            });

        $this->schedule = new Schedule();
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addSubscriber(new DispatchSchedulerEventListener(new ServiceLocator([
            'default' => function (): Schedule { return $this->schedule; },
            'reports' => function (): Schedule { return $this->schedule; },
        ]), $this->dispatcher));
        $this->setListener(new SchedulerListener($this->hub));
    }

    public function testSuccessfulRun(): void
    {
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));

        $this->assertTrue($this->consume($this->getScheduledEnvelope()));
        $this->assertCount(2, $this->checkIns);

        [$start, $finish] = $this->checkIns;

        $this->assertSame('default-send-daily-report-message', $start['slug']);
        $this->assertSame(CheckInStatus::inProgress(), $start['status']);
        $this->assertNull($start['duration']);
        $this->assertNull($start['check_in_id']);

        $this->assertSame($start['slug'], $finish['slug']);
        $this->assertSame(CheckInStatus::ok(), $finish['status']);
        $this->assertIsFloat($finish['duration']);
        $this->assertSame('check-in-id-1', $finish['check_in_id']);
    }

    public function testFailedRun(): void
    {
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));

        $this->consume($this->getScheduledEnvelope(), new \RuntimeException());

        $this->assertCount(2, $this->checkIns);
        $this->assertSame(CheckInStatus::error(), $this->checkIns[1]['status']);
        $this->assertSame('check-in-id-1', $this->checkIns[1]['check_in_id']);
    }

    public function testRunCancelledByAListenerIsNotReported(): void
    {
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));
        $this->dispatcher->addListener(PreRunEvent::class, static function (PreRunEvent $event): void {
            $event->shouldCancel(true);
        }, -2048);

        $this->assertFalse($this->consume($this->getScheduledEnvelope()));
        $this->assertSame([], $this->checkIns);
    }

    public function testRunCancelledByTheScheduleIsNotReported(): void
    {
        // Symfony 8.2+ keeps the listeners of before() on the schedule and runs them after the ones of the application
        $this->schedule = class_exists('Symfony\Component\Messenger\Stamp\RedispatchStamp') ? new Schedule() : new Schedule($this->dispatcher);
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));
        $this->schedule->before(static function (PreRunEvent $event): void {
            $event->shouldCancel(true);
        });

        $this->assertFalse($this->consume($this->getScheduledEnvelope()));
        $this->assertSame([], $this->checkIns);
    }

    public function testFinishWithoutStartIsIgnored(): void
    {
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));

        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($this->getScheduledEnvelope(), 'scheduler_default'));

        $this->assertSame([], $this->checkIns);
    }

    public function testResetKeepsStartedCheckIns(): void
    {
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));
        $envelope = $this->getScheduledEnvelope();
        $listener = new SchedulerListener($this->hub);
        $this->setListener($listener);

        $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
        $listener->reset();
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($envelope, 'scheduler_default'));

        $this->assertSame([CheckInStatus::inProgress(), CheckInStatus::ok()], array_column($this->checkIns, 'status'));
    }

    public function testOldestStartedCheckInIsDroppedWhenTooManyAreStarted(): void
    {
        $recurringMessage = RecurringMessage::cron('0 * * * *', new SendDailyReportMessage());
        $this->schedule->add($recurringMessage);
        $envelopes = [];

        for ($i = 0; $i <= 100; ++$i) {
            $envelopes[] = $envelope = new Envelope(new SendDailyReportMessage(), [new ScheduledStamp($this->createContext($recurringMessage))]);
            $this->dispatcher->dispatch(new WorkerMessageReceivedEvent($envelope, 'scheduler_default'));
        }

        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($envelopes[0], 'scheduler_default'));
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent($envelopes[1], 'scheduler_default'));

        $this->assertCount(102, $this->checkIns);
        $this->assertSame(CheckInStatus::ok(), $this->checkIns[101]['status']);
        $this->assertSame('check-in-id-2', $this->checkIns[101]['check_in_id']);
    }

    public function testMessageSentToAnotherTransportIsNotReported(): void
    {
        $this->setListener(new SchedulerListener($this->hub, $this->createSendersLocator()));
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new RedispatchMessage(new SendDailyReportMessage(), 'async')));

        $this->consume($this->getScheduledEnvelope());

        $this->assertSame([], $this->checkIns);
    }

    public function testRedispatchedMessageIsReportedWhenConsumed(): void
    {
        $message = new SendDailyReportMessage();
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new RedispatchMessage($message, 'async')));
        $stamp = $this->getScheduledEnvelope()->last(ScheduledStamp::class);
        $this->assertNotNull($stamp);

        $this->consume(new Envelope($message, [$stamp]));

        $this->assertSame([CheckInStatus::inProgress(), CheckInStatus::ok()], array_column($this->checkIns, 'status'));
        $this->assertSame('default-send-daily-report-message', $this->checkIns[0]['slug']);
    }

    /**
     * @dataProvider handledRedispatchMessageDataProvider
     *
     * @param string|string[] $transportNames
     */
    public function testRedispatchMessageHandledByTheSchedulerWorkerIsReported($transportNames): void
    {
        $this->setListener(new SchedulerListener($this->hub, $this->createSendersLocator()));
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new RedispatchMessage(new SendDailyReportMessage(), $transportNames)));

        $this->consume($this->getScheduledEnvelope());

        $this->assertSame([CheckInStatus::inProgress(), CheckInStatus::ok()], array_column($this->checkIns, 'status'));
        $this->assertSame('default-send-daily-report-message', $this->checkIns[0]['slug']);
    }

    /**
     * @return iterable<string, array{string|string[]}>
     */
    public function handledRedispatchMessageDataProvider(): iterable
    {
        yield 'sync transport' => ['sync'];
        yield 'no transport and no route' => [[]];
    }

    public function testRedispatchMessageToAnUnknownTransportIsNotReported(): void
    {
        $this->setListener(new SchedulerListener($this->hub, $this->createSendersLocator()));
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new RedispatchMessage(new SendDailyReportMessage(), 'typo')));
        $this->logger->expects($this->once())->method('warning')->with('Failed to send a cron check-in for a scheduled message.');

        $this->consume($this->getScheduledEnvelope());

        $this->assertSame([], $this->checkIns);
    }

    public function testRoutedMessageIsNotReported(): void
    {
        if (!class_exists('Symfony\Component\Messenger\Stamp\RedispatchStamp')) {
            $this->markTestSkipped('This test requires the "symfony/messenger" Composer package (8.2 or newer) to be installed.');
        }

        $this->setListener(new SchedulerListener($this->hub, $this->createSendersLocator([SendDailyReportMessage::class => ['async']])));
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));

        $this->consume($this->getScheduledEnvelope(true));

        $this->assertSame([], $this->checkIns);
    }

    /**
     * @dataProvider routedToTheSchedulerWorkerDataProvider
     *
     * @param array<string, string[]> $routes
     */
    public function testMessageRoutedToTheSchedulerWorkerIsReported(array $routes): void
    {
        $this->setListener(new SchedulerListener($this->hub, $this->createSendersLocator($routes)));
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));

        $this->consume($this->getScheduledEnvelope(true));

        $this->assertSame(['default-send-daily-report-message', 'default-send-daily-report-message'], array_column($this->checkIns, 'slug'));
    }

    /**
     * @return iterable<string, array{array<string, string[]>}>
     */
    public function routedToTheSchedulerWorkerDataProvider(): iterable
    {
        yield 'no route' => [[]];
        yield 'sync transport' => [[SendDailyReportMessage::class => ['sync']]];
    }

    public function testAnonymousMessageIsNotReported(): void
    {
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new StaticMessageProvider([new class {
        }], 'anonymous')));

        $this->consume($this->getScheduledEnvelope());

        $this->assertSame([], $this->checkIns);
    }

    public function testRedeliveredMessageIsNotReported(): void
    {
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));
        $envelope = $this->getScheduledEnvelope();

        $this->consume($envelope->with(new RedeliveryStamp(1)));

        $this->assertCount(0, $this->checkIns);

        $this->consume($envelope);

        $this->assertCount(2, $this->checkIns);
    }

    public function testFailingCheckInIsLoggedAndDoesNotThrow(): void
    {
        $this->logger->expects($this->once())->method('warning')->with('Failed to send a cron check-in for a scheduled message.');

        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturn(new Options(['logger' => $this->logger]));

        $hub = $this->createMock(HubInterface::class);
        $hub->method('getClient')->willReturn($client);
        $hub->method('captureCheckIn')->willThrowException(new \RuntimeException());

        $this->setListener(new SchedulerListener($hub));
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()));

        $this->assertTrue($this->consume($this->getScheduledEnvelope()));
    }

    /**
     * @dataProvider slugDataProvider
     */
    public function testSlug(string $scheduleName, callable $createMessage, string $expectedSlug): void
    {
        $this->schedule->add(RecurringMessage::cron('0 0 * * *', $createMessage()));

        $this->consume($this->getScheduledEnvelope(false, $scheduleName));

        $this->assertCount(2, $this->checkIns);
        $this->assertSame($expectedSlug, $this->checkIns[0]['slug']);
        $this->assertLessThanOrEqual(50, \strlen($this->checkIns[0]['slug']));
    }

    /**
     * @return iterable<string, array{string, callable(): object, string}>
     */
    public function slugDataProvider(): iterable
    {
        yield 'message class' => ['default', static function () { return new SendDailyReportMessage(); }, 'default-send-daily-report-message'];
        yield 'command' => ['default', static function () { return new RunCommandMessage('app:send-report --force'); }, 'default-app-send-report-force'];
        yield 'service call' => ['default', static function () { return new ServiceCallMessage('app.report_sender', 'send'); }, 'default-app-report_sender-send'];
        yield 'service call with __invoke' => ['default', static function () { return new ServiceCallMessage('App\Report\Sender'); }, 'default-app-report-sender'];
        yield 'message in an envelope' => ['default', static function () { return new Envelope(new SendDailyReportMessage()); }, 'default-send-daily-report-message'];
        yield 'named schedule' => ['reports', static function () { return new SendDailyReportMessage(); }, 'reports-send-daily-report-message'];
        yield 'long slug' => ['default', static function () { return new RunCommandMessage('app:a-very-long-command-name --with-many --options --and-more'); }, 'default-app-a-very-long-command-name-with-many-opt'];
    }

    /**
     * @dataProvider sharedSlugDataProvider
     */
    public function testMessagesThatShareASlugAreNotReported(callable $createFirst, callable $createSecond, string $sharedSlug): void
    {
        $this->setListener(new SchedulerListener($this->hub, $this->createSendersLocator()));
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(\sprintf('Not monitoring the scheduled messages with the slug "%s" because more than one recurring message uses it. Use a separate message class or schedule for each one.', $sharedSlug));

        $this->schedule->add(
            RecurringMessage::cron('0 * * * *', $createFirst()),
            RecurringMessage::cron('0 0 * * *', $createSecond()),
            RecurringMessage::cron('0 0 * * *', new RunCommandMessage('app:other'))
        );

        foreach ($this->getScheduledEnvelopes() as $envelope) {
            $this->consume($envelope);
        }

        $this->assertSame(['default-app-other', 'default-app-other'], array_column($this->checkIns, 'slug'));
    }

    /**
     * @return iterable<string, array{callable(): object, callable(): object, string}>
     */
    public function sharedSlugDataProvider(): iterable
    {
        $report = static function (): object { return new SendDailyReportMessage(); };
        $command = static function (string $command): callable {
            return static function () use ($command): object { return new RunCommandMessage($command); };
        };

        yield 'message class' => [$report, $report, 'default-send-daily-report-message'];
        yield 'command' => [$command('app:send-report'), $command('app:send-report'), 'default-app-send-report'];
        yield 'redispatched message' => [static function (): object { return new RedispatchMessage(new SendDailyReportMessage(), 'async'); }, $report, 'default-send-daily-report-message'];
        yield 'slug cut to the same length' => [
            $command('app:a-very-long-command-name --with-many --options --first'),
            $command('app:a-very-long-command-name --with-many --options --second'),
            'default-app-a-very-long-command-name-with-many-opt',
        ];
    }

    public function testMessageOfAnotherProviderIsReported(): void
    {
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new CallbackMessageProvider(static function (): iterable {
            return [new SendDailyReportMessage()];
        }, 'reports')));

        $this->consume($this->getScheduledEnvelope());

        $this->assertSame(['default-send-daily-report-message', 'default-send-daily-report-message'], array_column($this->checkIns, 'slug'));
    }

    public function testMessageOfAnotherProviderThatSharesASlugIsNotReported(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Not monitoring the scheduled messages with the slug "default-send-daily-report-message" because more than one recurring message uses it. Use a separate message class or schedule for each one.');

        $this->schedule->add(
            RecurringMessage::cron('0 0 * * *', new SendDailyReportMessage()),
            RecurringMessage::cron('0 * * * *', new CallbackMessageProvider(static function (): iterable {
                return [new SendDailyReportMessage(), new RunCommandMessage('app:other')];
            }, 'reports'))
        );

        $staticId = $this->schedule->getRecurringMessages()[0]->getId();

        foreach ($this->getScheduledEnvelopes() as $envelope) {
            $stamp = $envelope->last(ScheduledStamp::class);
            $this->assertNotNull($stamp);

            if ($stamp->messageContext->id !== $staticId) {
                $this->consume($envelope);
            }
        }

        $this->assertSame(['default-app-other', 'default-app-other'], array_column($this->checkIns, 'slug'));
    }

    public function testMessageMissingFromTheScheduleIsNotReported(): void
    {
        $recurringMessage = RecurringMessage::cron('0 * * * *', new SendDailyReportMessage());

        $this->consume(new Envelope(new SendDailyReportMessage(), [new ScheduledStamp($this->createContext($recurringMessage))]));

        $this->assertSame([], $this->checkIns);
    }

    private function setListener(SchedulerListener $listener): void
    {
        if (null !== $this->listener) {
            $this->dispatcher->removeListener(PreRunEvent::class, [$this->listener, 'handlePreRunEvent']);
            $this->dispatcher->removeListener(WorkerMessageReceivedEvent::class, [$this->listener, 'handleWorkerMessageReceivedEvent']);
            $this->dispatcher->removeListener(PostRunEvent::class, [$this->listener, 'handlePostRunEvent']);
            $this->dispatcher->removeListener(FailureEvent::class, [$this->listener, 'handleFailureEvent']);
        }

        // The same priorities as in services.yaml
        $this->listener = $listener;
        $this->dispatcher->addListener(PreRunEvent::class, [$listener, 'handlePreRunEvent']);
        $this->dispatcher->addListener(WorkerMessageReceivedEvent::class, [$listener, 'handleWorkerMessageReceivedEvent'], -1024);
        $this->dispatcher->addListener(PostRunEvent::class, [$listener, 'handlePostRunEvent']);
        $this->dispatcher->addListener(FailureEvent::class, [$listener, 'handleFailureEvent']);
    }

    /**
     * Dispatches the events of a worker that consumes the envelope, and returns whether it handled it.
     */
    private function consume(Envelope $envelope, ?\Throwable $failure = null): bool
    {
        $event = new WorkerMessageReceivedEvent($envelope, 'scheduler_default');
        $this->dispatcher->dispatch($event);

        if (!$event->shouldHandle()) {
            return false;
        }

        $this->dispatcher->dispatch(null === $failure ? new WorkerMessageHandledEvent($envelope, 'scheduler_default') : new WorkerMessageFailedEvent($envelope, 'scheduler_default', $failure));

        return true;
    }

    private function getScheduledEnvelope(bool $useMessengerRouting = false, string $scheduleName = 'default'): Envelope
    {
        $envelopes = $this->getScheduledEnvelopes($useMessengerRouting, $scheduleName);
        $this->assertCount(1, $envelopes);

        return $envelopes[0];
    }

    /**
     * Returns the envelopes that the scheduler transport yields for the first hour of 2026.
     *
     * @return list<Envelope>
     */
    private function getScheduledEnvelopes(bool $useMessengerRouting = false, string $scheduleName = 'default'): array
    {
        $clock = new MockClock('2025-12-31 23:59:30', 'UTC');
        // The $useMessengerRouting argument exists since Symfony 8.2, and older versions ignore it
        $transport = (new \ReflectionClass(SchedulerTransport::class))->newInstance(new MessageGenerator($this->schedule, $scheduleName, $clock), $useMessengerRouting);

        foreach ($transport->get() as $envelope) {
            $this->fail('The schedule starts with no runs.');
        }

        $clock->sleep(3570);

        return iterator_to_array($transport->get(), false);
    }

    /**
     * @param array<string, string[]> $routes
     */
    private function createSendersLocator(array $routes = []): SendersLocator
    {
        return new SendersLocator($routes, new ServiceLocator([
            'async' => static function (): InMemoryTransport { return new InMemoryTransport(); },
            'sync' => static function (): SyncTransport { return new SyncTransport(new MessageBus()); },
        ]));
    }

    private function createContext(RecurringMessage $recurringMessage): MessageContext
    {
        $trigger = $recurringMessage->getTrigger();
        $triggeredAt = $trigger->getNextRunDate(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $this->assertNotNull($triggeredAt);

        return new MessageContext('default', $recurringMessage->getId(), $trigger, $triggeredAt, $trigger->getNextRunDate($triggeredAt));
    }
}
