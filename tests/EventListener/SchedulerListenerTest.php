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
use Symfony\Component\Scheduler\Trigger\CallbackTrigger;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\ExcludeTimeTrigger;
use Symfony\Component\Scheduler\Trigger\JitterTrigger;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

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
        $this->setListener(new SchedulerListener($this->hub, null, ['app:excluded', '/^default-cleanup/']));
    }

    public function testSuccessfulRun(): void
    {
        $this->schedule->add(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage(), 'Europe/Vienna'));

        $this->assertTrue($this->consume($this->getScheduledEnvelope()));
        $this->assertCount(2, $this->checkIns);

        [$start, $finish] = $this->checkIns;

        $this->assertSame('default-send-daily-report-message', $start['slug']);
        $this->assertSame(CheckInStatus::inProgress(), $start['status']);
        $this->assertNull($start['duration']);
        $this->assertNull($start['check_in_id']);
        $this->assertNotNull($start['monitor_config']);
        $this->assertSame([
            'schedule' => ['type' => 'crontab', 'value' => '0 * * * *', 'unit' => ''],
            'checkin_margin' => null,
            'max_runtime' => null,
            'timezone' => 'Europe/Vienna',
            'failure_issue_threshold' => null,
            'recovery_threshold' => null,
        ], $start['monitor_config']->toArray());

        $this->assertSame($start['slug'], $finish['slug']);
        $this->assertSame(CheckInStatus::ok(), $finish['status']);
        $this->assertIsFloat($finish['duration']);
        $this->assertSame('check-in-id-1', $finish['check_in_id']);
        $this->assertSame($start['monitor_config'], $finish['monitor_config']);
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
     */
    public function testRedispatchMessageHandledByTheSchedulerWorkerIsReported(RedispatchMessage $message): void
    {
        $this->setListener(new SchedulerListener($this->hub, $this->createSendersLocator()));
        $this->schedule->add(RecurringMessage::cron('0 * * * *', $message));

        $this->consume($this->getScheduledEnvelope());

        $this->assertSame([CheckInStatus::inProgress(), CheckInStatus::ok()], array_column($this->checkIns, 'status'));
        $this->assertSame('default-send-daily-report-message', $this->checkIns[0]['slug']);
    }

    /**
     * @return iterable<string, array{RedispatchMessage}>
     */
    public function handledRedispatchMessageDataProvider(): iterable
    {
        yield 'sync transport' => [new RedispatchMessage(new SendDailyReportMessage(), 'sync')];
        yield 'no transport and no route' => [new RedispatchMessage(new SendDailyReportMessage(), [])];
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

    /**
     * @dataProvider excludedMessageDataProvider
     */
    public function testExcludedMessageIsNotReported(?string $command, bool $isExcluded): void
    {
        $this->schedule->add(RecurringMessage::cron('0 0 * * *', null !== $command ? new RunCommandMessage($command) : new SendDailyReportMessage()));

        $this->consume($this->getScheduledEnvelope());

        $this->assertCount($isExcluded ? 0 : 2, $this->checkIns);
    }

    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public function excludedMessageDataProvider(): iterable
    {
        yield 'command name' => ['app:excluded --force', true];
        yield 'slug pattern' => ['cleanup', true];
        yield 'other command' => ['app:included', false];
        yield 'other message class' => [null, false];
    }

    /**
     * @dataProvider excludedMessageClassDataProvider
     */
    public function testExcludedMessageClassIsNotReported(string $excluded): void
    {
        $this->setListener(new SchedulerListener($this->hub, null, [$excluded]));
        $this->schedule->add(RecurringMessage::cron('0 0 * * *', new SendDailyReportMessage()));

        $this->consume($this->getScheduledEnvelope());

        $this->assertSame([], $this->checkIns);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public function excludedMessageClassDataProvider(): iterable
    {
        yield 'message class' => [SendDailyReportMessage::class];
        yield 'message class with a leading backslash' => ['\\' . SendDailyReportMessage::class];
        yield 'slug' => ['default-send-daily-report-message'];
        yield 'regular expression' => ['/report-message$/'];
        yield 'regular expression with flags' => ['/^DEFAULT-SEND-/i'];
    }

    public function testInvalidRegularExpressionIsLoggedAndIgnored(): void
    {
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with('Ignoring the invalid regular expression "/[/" in the excluded messages of the scheduler.');

        $this->setListener(new SchedulerListener($this->hub, null, ['/[/']));
        $this->schedule->add(RecurringMessage::cron('0 0 * * *', new SendDailyReportMessage()));

        $this->consume($this->getScheduledEnvelope());

        $this->assertSame([CheckInStatus::inProgress(), CheckInStatus::ok()], array_column($this->checkIns, 'status'));
    }

    public function testExcludedMessageDoesNotShareItsSlug(): void
    {
        $this->logger->expects($this->never())->method('warning');

        $this->setListener(new SchedulerListener($this->hub, null, ['app:send-report -all']));
        $this->schedule->add(
            RecurringMessage::cron('0 * * * *', new RunCommandMessage('app:send-report --all')),
            RecurringMessage::cron('0 0 * * *', new RunCommandMessage('app:send-report -all'))
        );

        foreach ($this->getScheduledEnvelopes() as $envelope) {
            $this->consume($envelope);
        }

        $this->assertSame(['default-app-send-report-all', 'default-app-send-report-all'], array_column($this->checkIns, 'slug'));
    }

    public function testScheduleOfMessageThatCrossedATransport(): void
    {
        $message = new SendDailyReportMessage();
        $recurringMessage = RecurringMessage::cron('0 3 * * *', $message, 'Europe/Vienna');
        $context = $this->createContext($recurringMessage);

        // Symfony keeps only the trigger's description when it serializes the context
        $trigger = new CallbackTrigger(static function (): ?\DateTimeImmutable {
            throw new \LogicException('Not possible to get next run date from a deserialized trigger.');
        }, (string) $recurringMessage->getTrigger());
        $context = new MessageContext($context->name, $context->id, $trigger, $context->triggeredAt, $context->nextTriggerAt);

        $this->runPreRunEvents($recurringMessage, $context);

        $monitorConfig = $this->checkIns[0]['monitor_config'];

        $this->assertNotNull($monitorConfig);
        $this->assertSame('0 3 * * *', $monitorConfig->getSchedule()->getValue());
        $this->assertSame('Europe/Vienna', $monitorConfig->getTimezone());
    }

    public function testJitterWidensCheckInMargin(): void
    {
        $this->runPreRunEvents(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage())->withJitter(90)->withJitter(30));

        $this->assertNotNull($this->checkIns[0]['monitor_config']);
        $this->assertSame(3, $this->checkIns[0]['monitor_config']->getCheckinMargin());
    }

    /**
     * @dataProvider timezoneDataProvider
     */
    public function testTimezone(string $timezone, ?string $expectedTimezone): void
    {
        $this->runPreRunEvents(RecurringMessage::cron('0 3 * * *', new SendDailyReportMessage(), $timezone));

        $this->assertCount(null === $expectedTimezone ? 0 : 1, $this->checkIns);

        $monitorConfig = $this->checkIns[0]['monitor_config'] ?? null;

        $this->assertSame($expectedTimezone, null !== $monitorConfig ? $monitorConfig->getTimezone() : null);
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public function timezoneDataProvider(): iterable
    {
        yield 'identifier' => ['America/New_York', 'America/New_York'];
        yield 'UTC' => ['UTC', 'UTC'];
        yield 'zero offset' => ['+00:00', 'UTC'];
        yield 'positive offset' => ['+05:00', 'Etc/GMT-5'];
        yield 'negative offset' => ['-03:00', 'Etc/GMT+3'];
        yield 'abbreviation' => ['CEST', 'Etc/GMT-2'];
        yield 'half hour offset' => ['+05:30', null];
    }

    public function testCronWithWeekdayNamesAndStepIsNotMonitored(): void
    {
        // Older cron-expression versions can't compute the run dates, so build the context by hand
        $recurringMessage = RecurringMessage::trigger(CronExpressionTrigger::fromSpec('0 0 * * MON-FRI/2'), new SendDailyReportMessage());
        $context = new MessageContext('default', $recurringMessage->getId(), $recurringMessage->getTrigger(), new \DateTimeImmutable('2026-01-02 00:00:00', new \DateTimeZone('UTC')));

        $this->runPreRunEvents($recurringMessage, $context);

        $this->assertSame([], $this->checkIns);
    }

    public function testHashedCronExpression(): void
    {
        $recurringMessage = RecurringMessage::cron('#midnight', new RunCommandMessage('app:send-report'));
        $this->runPreRunEvents($recurringMessage);

        $this->assertNotNull($this->checkIns[0]['monitor_config']);
        $this->assertSame((string) $recurringMessage->getTrigger(), $this->checkIns[0]['monitor_config']->getSchedule()->getValue());
    }

    /**
     * @dataProvider scheduleDataProvider
     *
     * @param array<string, mixed>|null $expectedSchedule
     */
    public function testSchedule(callable $createTrigger, ?array $expectedSchedule): void
    {
        $this->runPreRunEvents(RecurringMessage::trigger($createTrigger(), new SendDailyReportMessage()));

        $this->assertCount(null === $expectedSchedule ? 0 : 1, $this->checkIns);

        $monitorConfig = $this->checkIns[0]['monitor_config'] ?? null;

        $this->assertSame($expectedSchedule, null !== $monitorConfig ? $monitorConfig->getSchedule()->toArray() : null);
    }

    /**
     * @return iterable<string, array{callable(): TriggerInterface, array<string, mixed>|null}>
     */
    public function scheduleDataProvider(): iterable
    {
        $crontab = static function (string $value): array {
            return ['type' => 'crontab', 'value' => $value, 'unit' => ''];
        };
        $interval = static function (int $value, string $unit): array {
            return ['type' => 'interval', 'value' => $value, 'unit' => $unit];
        };
        $excluded = static function (TriggerInterface $trigger): TriggerInterface {
            return new ExcludeTimeTrigger($trigger, new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-02'));
        };

        yield 'cron' => [static function () { return CronExpressionTrigger::fromSpec('15 * * * 1'); }, $crontab('15 * * * 1')];
        yield 'cron alias' => [static function () { return CronExpressionTrigger::fromSpec('@daily'); }, $crontab('0 0 * * *')];
        yield 'cron with names' => [static function () { return CronExpressionTrigger::fromSpec('0 9 * JAN-JUN mon-fri'); }, $crontab('0 9 * JAN-JUN mon-fri')];
        yield 'cron with steps and lists' => [static function () { return CronExpressionTrigger::fromSpec('*/15 8-18/2 1,15 * *'); }, $crontab('*/15 8-18/2 1,15 * *')];
        yield 'cron with both days' => [static function () { return CronExpressionTrigger::fromSpec('0 0 1-7 * 1'); }, $crontab('0 0 1-7 * 1')];
        yield 'cron last day of month' => [static function () { return CronExpressionTrigger::fromSpec('0 0 L * *'); }, $crontab('0 0 L * *')];
        yield 'cron last friday' => [static function () { return CronExpressionTrigger::fromSpec('0 0 * * 5L'); }, $crontab('0 0 * * 5L')];
        yield 'cron second friday' => [static function () { return CronExpressionTrigger::fromSpec('0 0 * * FRI#2', 'context'); }, $crontab('0 0 * * FRI#2')];
        yield 'cron question mark' => [static function () { return CronExpressionTrigger::fromSpec('0 0 ? * MON'); }, $crontab('0 0 * * MON')];
        yield 'cron with jitter' => [static function () { return new JitterTrigger(CronExpressionTrigger::fromSpec('0 * * * *')); }, $crontab('0 * * * *')];
        yield 'cron last weekday of month' => [static function () { return CronExpressionTrigger::fromSpec('0 0 LW * *'); }, null];
        yield 'cron nearest weekday' => [static function () { return CronExpressionTrigger::fromSpec('0 0 15W * *'); }, null];
        yield 'cron last friday by name' => [static function () { return CronExpressionTrigger::fromSpec('0 0 * * FRIL'); }, null];
        yield 'cron day step and weekday' => [static function () { return CronExpressionTrigger::fromSpec('0 0 */2 * 1'); }, null];
        yield 'cron day and weekday step' => [static function () { return CronExpressionTrigger::fromSpec('0 0 1 * */2'); }, null];
        yield 'cron with a reversed range' => [static function () { return CronExpressionTrigger::fromSpec('0 1,22-2 * * *'); }, null];
        yield 'cron with a step longer than the field' => [static function () { return CronExpressionTrigger::fromSpec('*/90 * * * *'); }, null];
        yield 'cron with a weekday range ending on sunday' => [static function () { return CronExpressionTrigger::fromSpec('0 0 * * SAT-SUN'); }, null];
        yield 'cron with excluded time' => [static function () use ($excluded) { return $excluded(CronExpressionTrigger::fromSpec('0 * * * *')); }, null];
        yield 'cron with jitter and excluded time' => [static function () use ($excluded) { return new JitterTrigger($excluded(CronExpressionTrigger::fromSpec('0 * * * *'))); }, null];
        yield 'every minute' => [static function () { return new PeriodicalTrigger(60); }, $interval(1, 'minute')];
        yield 'every 5 minutes' => [static function () { return new PeriodicalTrigger('PT5M'); }, $interval(5, 'minute')];
        yield 'every 90 minutes' => [static function () { return new PeriodicalTrigger('90 minutes'); }, $interval(90, 'minute')];
        yield 'every 2 hours' => [static function () { return new PeriodicalTrigger('2 hours'); }, $interval(2, 'hour')];
        yield 'every day' => [static function () { return new PeriodicalTrigger('P1D'); }, $interval(1, 'day')];
        yield 'every week' => [static function () { return new PeriodicalTrigger('P1W'); }, $interval(1, 'week')];
        yield 'every 30 seconds' => [static function () { return new PeriodicalTrigger(30); }, null];
        yield 'every 90 seconds' => [static function () { return new PeriodicalTrigger(90); }, null];
        yield 'periodical subclass' => [static function () { return new class(3600) extends PeriodicalTrigger {}; }, null];
        yield 'callback' => [static function () {
            return new CallbackTrigger(static function (\DateTimeImmutable $run): \DateTimeImmutable {
                return $run->modify('+1 hour');
            }, 'hourly');
        }, null];
    }

    /**
     * @dataProvider calendarIntervalDataProvider
     *
     * @param array<string, mixed>|null $expectedSchedule
     */
    public function testCalendarInterval(string $interval, string $from, ?array $expectedSchedule, ?string $expectedTimezone = null): void
    {
        $trigger = new PeriodicalTrigger($interval, new \DateTimeImmutable($from));
        $this->runPreRunEvents(RecurringMessage::trigger($trigger, new SendDailyReportMessage()));

        $this->assertCount(null === $expectedSchedule ? 0 : 1, $this->checkIns);

        $monitorConfig = $this->checkIns[0]['monitor_config'] ?? null;

        $this->assertSame($expectedSchedule, null !== $monitorConfig ? $monitorConfig->getSchedule()->toArray() : null);
        $this->assertSame($expectedTimezone, null !== $monitorConfig ? $monitorConfig->getTimezone() : null);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null, 3?: string|null}>
     */
    public function calendarIntervalDataProvider(): iterable
    {
        $crontab = static function (string $value): array {
            return ['type' => 'crontab', 'value' => $value, 'unit' => ''];
        };
        $interval = static function (int $value, string $unit): array {
            return ['type' => 'interval', 'value' => $value, 'unit' => $unit];
        };

        yield 'day' => ['1 day', '2026-01-05 13:47 Europe/Vienna', $crontab('47 13 * * *'), 'Europe/Vienna'];
        yield 'week' => ['1 week', '2026-01-05 13:47 Europe/Vienna', $crontab('47 13 * * 1'), 'Europe/Vienna'];
        yield 'month' => ['1 month', '2026-01-15 08:05 UTC', $crontab('5 8 15 * *'), 'UTC'];
        yield 'quarter' => ['3 months', '2026-05-15 08:05 UTC', $crontab('5 8 15 2,5,8,11 *'), 'UTC'];
        yield 'year' => ['1 year', '2026-03-10 08:05 UTC', $crontab('5 8 10 3 *'), 'UTC'];
        yield 'days without DST' => ['2 days', '2026-01-05 13:47 UTC', $interval(2, 'day')];
        yield 'weeks without DST' => ['2 weeks', '2026-01-05 13:47 +02:00', $interval(2, 'week')];
        yield 'days with DST' => ['2 days', '2026-01-05 13:47 Europe/Vienna', null];
        yield 'month from a day some months lack' => ['1 month', '2026-01-31 08:05 UTC', null];
        yield 'months that do not divide a year' => ['5 months', '2026-01-15 08:05 UTC', null];
        yield 'day and hours' => ['1 day 2 hours', '2026-01-05 13:47 UTC', null];
        yield 'relative weekday' => ['next monday', '2026-01-05 13:47 UTC', null];
    }

    public function testCalendarIntervalObject(): void
    {
        $this->runPreRunEvents(RecurringMessage::trigger(new PeriodicalTrigger(new \DateInterval('P1D'), new \DateTimeImmutable('2026-01-05 13:47 UTC')), new SendDailyReportMessage()));

        $monitorConfig = $this->checkIns[0]['monitor_config'];

        $this->assertNotNull($monitorConfig);
        $this->assertSame('47 13 * * *', $monitorConfig->getSchedule()->getValue());
    }

    public function testCalendarIntervalKeepsTheTimeOfDayAcrossDst(): void
    {
        $trigger = new PeriodicalTrigger('1 day', new \DateTimeImmutable('2026-03-27 13:47 Europe/Vienna'));
        $run = new \DateTimeImmutable('2026-03-27 13:47 Europe/Vienna');

        // Symfony keeps the time of day, which is what the crontab in the time zone expects
        for ($i = 0; $i < 4; ++$i) {
            $run = $trigger->getNextRunDate($run);
            $this->assertNotNull($run);
            $this->assertSame('13:47', $run->format('H:i'));
        }

        $this->assertSame('+02:00', $run->format('P'));
    }

    public function testCalendarIntervalWithoutStartUsesTheRunTime(): void
    {
        $recurringMessage = RecurringMessage::every('1 day', new SendDailyReportMessage());
        $context = new MessageContext('default', $recurringMessage->getId(), $recurringMessage->getTrigger(), new \DateTimeImmutable('2026-01-02 06:30 Europe/Paris'));

        $this->runPreRunEvents($recurringMessage, $context);

        $monitorConfig = $this->checkIns[0]['monitor_config'];

        $this->assertNotNull($monitorConfig);
        $this->assertSame('30 6 * * *', $monitorConfig->getSchedule()->getValue());
        $this->assertSame('Europe/Paris', $monitorConfig->getTimezone());
    }

    public function testCalendarIntervalWithJitter(): void
    {
        $this->runPreRunEvents(RecurringMessage::every('1 day', new SendDailyReportMessage(), '2026-01-05 13:47 UTC')->withJitter(120));

        $monitorConfig = $this->checkIns[0]['monitor_config'];

        $this->assertNotNull($monitorConfig);
        $this->assertSame('47 13 * * *', $monitorConfig->getSchedule()->getValue());
        $this->assertSame(3, $monitorConfig->getCheckinMargin());
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

    /**
     * Starts the runs of the recurring message, at its first run of 2026 unless a context is given.
     */
    private function runPreRunEvents(RecurringMessage $recurringMessage, ?MessageContext $context = null): void
    {
        $this->schedule->add($recurringMessage);
        $context = $context ?? $this->createContext($recurringMessage);

        foreach ($recurringMessage->getMessages($context) as $message) {
            $this->dispatcher->dispatch(new WorkerMessageReceivedEvent(Envelope::wrap($message, [new ScheduledStamp($context)]), 'scheduler_default'));
        }
    }

    private function createContext(RecurringMessage $recurringMessage): MessageContext
    {
        $trigger = $recurringMessage->getTrigger();
        $triggeredAt = $trigger->getNextRunDate(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $this->assertNotNull($triggeredAt);

        return new MessageContext('default', $recurringMessage->getId(), $trigger, $triggeredAt, $trigger->getNextRunDate($triggeredAt));
    }
}
