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
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\CallbackTrigger;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\ExcludeTimeTrigger;
use Symfony\Component\Scheduler\Trigger\JitterTrigger;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

final class SchedulerListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var list<array{slug: string, status: CheckInStatus, duration: mixed, monitor_config: MonitorConfig|null, check_in_id: string|null}>
     */
    private $checkIns = [];

    /**
     * @var SchedulerListener
     */
    private $listener;

    protected function setUp(): void
    {
        if (!class_exists(PreRunEvent::class)) {
            $this->markTestSkipped('This test requires the "symfony/scheduler" Composer package (6.4 or newer) to be installed.');
        }

        $this->checkIns = [];
        $this->hub = $this->createMock(HubInterface::class);
        $this->hub->method('captureCheckIn')
            ->willReturnCallback(function (string $slug, CheckInStatus $status, $duration = null, ?MonitorConfig $monitorConfig = null, ?string $checkInId = null): string {
                $this->checkIns[] = [
                    'slug' => $slug,
                    'status' => $status,
                    'duration' => $duration,
                    'monitor_config' => $monitorConfig,
                    'check_in_id' => $checkInId,
                ];

                return $checkInId ?? 'check-in-id';
            });

        $this->listener = new SchedulerListener($this->hub);
    }

    public function testSuccessfulRun(): void
    {
        $context = $this->createContext(CronExpressionTrigger::fromSpec('0 3 * * *', null, 'Europe/Vienna'));
        $message = new SendDailyReportMessage();

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, $message));
        $this->listener->handlePostRunEvent(new PostRunEvent(new Schedule(), $context, $message));

        $this->assertCount(2, $this->checkIns);

        [$start, $finish] = $this->checkIns;

        $this->assertSame('default-send-daily-report-message-a1b2c3d4', $start['slug']);
        $this->assertSame(CheckInStatus::inProgress(), $start['status']);
        $this->assertNull($start['duration']);
        $this->assertNull($start['check_in_id']);
        $this->assertNotNull($start['monitor_config']);
        $this->assertSame([
            'schedule' => ['type' => 'crontab', 'value' => '0 3 * * *', 'unit' => ''],
            'checkin_margin' => null,
            'max_runtime' => null,
            'timezone' => 'Europe/Vienna',
            'failure_issue_threshold' => null,
            'recovery_threshold' => null,
        ], $start['monitor_config']->toArray());

        $this->assertSame($start['slug'], $finish['slug']);
        $this->assertSame(CheckInStatus::ok(), $finish['status']);
        $this->assertIsFloat($finish['duration']);
        $this->assertSame('check-in-id', $finish['check_in_id']);
        $this->assertSame($start['monitor_config'], $finish['monitor_config']);
    }

    public function testFailedRun(): void
    {
        $context = $this->createContext(CronExpressionTrigger::fromSpec('*/5 * * * *'));
        $message = new SendDailyReportMessage();

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, $message));
        $this->listener->handleFailureEvent(new FailureEvent(new Schedule(), $context, $message, new \RuntimeException()));

        $this->assertCount(2, $this->checkIns);
        $this->assertSame(CheckInStatus::error(), $this->checkIns[1]['status']);
        $this->assertSame('check-in-id', $this->checkIns[1]['check_in_id']);
    }

    public function testCancelledRunIsNotReported(): void
    {
        $event = new PreRunEvent(new Schedule(), $this->createContext(CronExpressionTrigger::fromSpec('*/5 * * * *')), new SendDailyReportMessage());
        $event->shouldCancel(true);

        $this->listener->handlePreRunEvent($event);

        $this->assertSame([], $this->checkIns);
    }

    public function testFinishWithoutStartIsIgnored(): void
    {
        $context = $this->createContext(CronExpressionTrigger::fromSpec('*/5 * * * *'));

        $this->listener->handlePostRunEvent(new PostRunEvent(new Schedule(), $context, new SendDailyReportMessage()));

        $this->assertSame([], $this->checkIns);
    }

    public function testResetDropsStartedCheckIns(): void
    {
        $context = $this->createContext(CronExpressionTrigger::fromSpec('*/5 * * * *'));
        $message = new SendDailyReportMessage();

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, $message));
        $this->listener->reset();
        $this->listener->handlePostRunEvent(new PostRunEvent(new Schedule(), $context, $message));

        $this->assertCount(1, $this->checkIns);
    }

    public function testRedispatchMessageIsNotReported(): void
    {
        $context = $this->createContext(CronExpressionTrigger::fromSpec('*/5 * * * *'));

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, new RedispatchMessage(new SendDailyReportMessage(), 'async')));

        $this->assertSame([], $this->checkIns);
    }

    public function testAnonymousMessageIsNotReported(): void
    {
        $context = $this->createContext(CronExpressionTrigger::fromSpec('*/5 * * * *'));

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, new class {
        }));

        $this->assertSame([], $this->checkIns);
    }

    public function testRedeliveredMessageIsNotReported(): void
    {
        $context = $this->createContext(CronExpressionTrigger::fromSpec('*/5 * * * *'));
        $message = new SendDailyReportMessage();
        $envelope = new Envelope($message, [new ScheduledStamp($context), new RedeliveryStamp(1)]);

        $this->listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent($envelope, 'async'));
        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, $message));

        $this->assertCount(0, $this->checkIns);

        $this->listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent(new Envelope($message, [new ScheduledStamp($context)]), 'async'));
        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, $message));

        $this->assertCount(1, $this->checkIns);
    }

    public function testFailingCheckInIsLoggedAndDoesNotThrow(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturn(new Options(['logger' => $logger]));

        $hub = $this->createMock(HubInterface::class);
        $hub->method('getClient')->willReturn($client);
        $hub->method('captureCheckIn')->willThrowException(new \RuntimeException());

        $listener = new SchedulerListener($hub);
        $listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $this->createContext(CronExpressionTrigger::fromSpec('*/5 * * * *')), new SendDailyReportMessage()));
    }

    /**
     * @dataProvider slugDataProvider
     */
    public function testSlug(string $scheduleName, callable $createMessage, string $expectedSlug): void
    {
        $message = $createMessage();
        $context = $this->createContext(CronExpressionTrigger::fromSpec('0 0 * * *'), $scheduleName);

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, $message));

        $this->assertCount(1, $this->checkIns);
        $this->assertSame($expectedSlug, $this->checkIns[0]['slug']);
        $this->assertLessThanOrEqual(50, \strlen($this->checkIns[0]['slug']));
    }

    /**
     * @return iterable<string, array{string, callable(): object, string}>
     */
    public function slugDataProvider(): iterable
    {
        yield 'message class' => ['default', static function () { return new SendDailyReportMessage(); }, 'default-send-daily-report-message-a1b2c3d4'];
        yield 'command' => ['default', static function () { return new RunCommandMessage('app:send-report --force'); }, 'default-app-send-report-force-a1b2c3d4'];
        yield 'service call' => ['default', static function () { return new ServiceCallMessage('app.report_sender', 'send'); }, 'default-app-report_sender-send-a1b2c3d4'];
        yield 'service call with __invoke' => ['default', static function () { return new ServiceCallMessage('App\Report\Sender'); }, 'default-app-report-sender-a1b2c3d4'];
        yield 'named schedule' => ['reports', static function () { return new SendDailyReportMessage(); }, 'reports-send-daily-report-message-a1b2c3d4'];
        yield 'long slug' => ['default', static function () { return new RunCommandMessage('app:a-very-long-command-name --with-many --options'); }, 'default-app-a-very-long-command-name-with-a1b2c3d4'];
    }

    public function testJitterWidensCheckInMargin(): void
    {
        $trigger = new JitterTrigger(new JitterTrigger(CronExpressionTrigger::fromSpec('0 * * * *'), 90), 30);

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $this->createContext($trigger), new SendDailyReportMessage()));

        $this->assertNotNull($this->checkIns[0]['monitor_config']);
        $this->assertSame(3, $this->checkIns[0]['monitor_config']->getCheckinMargin());
    }

    /**
     * @dataProvider timezoneDataProvider
     */
    public function testTimezone(string $timezone, ?string $expectedTimezone): void
    {
        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $this->createContext(CronExpressionTrigger::fromSpec('0 3 * * *', null, $timezone)), new SendDailyReportMessage()));

        $monitorConfig = $this->checkIns[0]['monitor_config'];

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

    public function testCronWithWeekdayNamesAndStepHasNoSchedule(): void
    {
        // Older cron-expression versions can't compute the run dates, so build the context by hand
        $trigger = CronExpressionTrigger::fromSpec('0 0 * * MON-FRI/2');
        $context = new MessageContext('default', 'a1b2c3d4', $trigger, new \DateTimeImmutable('2026-01-02 00:00:00', new \DateTimeZone('UTC')));

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, new SendDailyReportMessage()));

        $this->assertCount(1, $this->checkIns);
        $this->assertNull($this->checkIns[0]['monitor_config']);
    }

    public function testHashedCronExpression(): void
    {
        $trigger = CronExpressionTrigger::fromSpec('#midnight', 'context');

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $this->createContext($trigger), new SendDailyReportMessage()));

        $this->assertNotNull($this->checkIns[0]['monitor_config']);
        $this->assertSame((string) $trigger, $this->checkIns[0]['monitor_config']->getSchedule()->getValue());
    }

    /**
     * @dataProvider scheduleDataProvider
     *
     * @param array<string, mixed>|null $expectedSchedule
     */
    public function testSchedule(callable $createTrigger, ?array $expectedSchedule): void
    {
        $trigger = $createTrigger();
        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $this->createContext($trigger), new SendDailyReportMessage()));

        $this->assertCount(1, $this->checkIns);

        $monitorConfig = $this->checkIns[0]['monitor_config'];

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
        yield 'every day as calendar interval' => [static function () { return new PeriodicalTrigger('1 day'); }, null];
        yield 'every month' => [static function () { return new PeriodicalTrigger('1 month'); }, null];
        yield 'callback' => [static function () {
            return new CallbackTrigger(static function (\DateTimeImmutable $run): \DateTimeImmutable {
                return $run->modify('+1 hour');
            }, 'hourly');
        }, null];
    }

    private function createContext(TriggerInterface $trigger, string $scheduleName = 'default'): MessageContext
    {
        $triggeredAt = $trigger->getNextRunDate(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $this->assertNotNull($triggeredAt);

        return new MessageContext($scheduleName, 'a1b2c3d4', $trigger, $triggeredAt, $trigger->getNextRunDate($triggeredAt));
    }
}
