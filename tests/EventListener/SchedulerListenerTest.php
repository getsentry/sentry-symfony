<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\SentryBundle\EventListener\SchedulerListener;
use Sentry\SentryBundle\Tests\EventListener\Fixtures\SendDailyReportMessage;
use Sentry\SentryBundle\Tests\EventListener\Fixtures\StringableReportMessage;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\Trigger\CallbackTrigger;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
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

        $this->assertSame('send-daily-report-message', $start['slug']);
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

        $this->assertSame('send-daily-report-message', $finish['slug']);
        $this->assertSame(CheckInStatus::ok(), $finish['status']);
        $this->assertIsFloat($finish['duration']);
        $this->assertSame($start['monitor_config'], $finish['monitor_config']);
        $this->assertSame('check-in-id', $finish['check_in_id']);
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
        $context = $this->createContext(CronExpressionTrigger::fromSpec('*/5 * * * *'));
        $message = new SendDailyReportMessage();
        $event = new PreRunEvent(new Schedule(), $context, $message);
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

    /**
     * @dataProvider scheduleDataProvider
     *
     * @param array<string, mixed>|null $expectedSchedule
     */
    public function testSchedule(string $triggerType, string $spec, ?array $expectedSchedule): void
    {
        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $this->createContext($this->createTrigger($triggerType, $spec)), new SendDailyReportMessage()));

        if (null === $expectedSchedule) {
            $this->assertSame([], $this->checkIns);

            return;
        }

        $this->assertCount(1, $this->checkIns);
        $this->assertNotNull($this->checkIns[0]['monitor_config']);
        $this->assertSame($expectedSchedule, $this->checkIns[0]['monitor_config']->getSchedule()->toArray());
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public function scheduleDataProvider(): iterable
    {
        yield 'cron' => ['cron', '15 * * * 1', ['type' => 'crontab', 'value' => '15 * * * 1', 'unit' => '']];
        yield 'cron alias' => ['cron', '@daily', ['type' => 'crontab', 'value' => '0 0 * * *', 'unit' => '']];
        yield 'cron with jitter' => ['jitter', '0 * * * *', ['type' => 'crontab', 'value' => '0 * * * *', 'unit' => '']];
        yield 'every minute' => ['every', '60', ['type' => 'interval', 'value' => 1, 'unit' => 'minute']];
        yield 'every 90 minutes' => ['every', '90 minutes', ['type' => 'interval', 'value' => 90, 'unit' => 'minute']];
        yield 'every 2 hours' => ['every', 'PT2H', ['type' => 'interval', 'value' => 2, 'unit' => 'hour']];
        yield 'every day' => ['every', 'P1D', ['type' => 'interval', 'value' => 1, 'unit' => 'day']];
        yield 'every week' => ['every', 'P1W', ['type' => 'interval', 'value' => 1, 'unit' => 'week']];
        yield 'every 30 seconds' => ['every', '30', null];
        yield 'every 90 seconds' => ['every', '90', null];
        yield 'every month' => ['every', '1 month', null];
        yield 'callback' => ['callback', '', null];
    }

    /**
     * @dataProvider slugDataProvider
     */
    public function testSlug(string $scheduleName, string $messageType, string $messageValue, string $expectedSlug): void
    {
        switch ($messageType) {
            case 'command':
                $message = new RunCommandMessage($messageValue);
                break;
            case 'service':
                [$serviceId, $method] = explode('::', $messageValue);
                $message = new ServiceCallMessage($serviceId, $method);
                break;
            case 'stringable':
                $message = new StringableReportMessage($messageValue);
                break;
            default:
                $message = new SendDailyReportMessage();
        }

        $context = $this->createContext(CronExpressionTrigger::fromSpec('0 0 * * *'), $scheduleName);

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, $message));

        $this->assertCount(1, $this->checkIns);
        $this->assertSame($expectedSlug, $this->checkIns[0]['slug']);
        $this->assertLessThanOrEqual(50, \strlen($this->checkIns[0]['slug']));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public function slugDataProvider(): iterable
    {
        yield 'message class' => ['default', 'class', '', 'send-daily-report-message'];
        yield 'stringable message' => ['default', 'stringable', 'weekly', 'stringable-report-message-weekly'];
        yield 'command' => ['default', 'command', 'app:send-report --force', 'app-send-report-force'];
        yield 'service call' => ['default', 'service', 'app.report_sender::send', 'app-report_sender-send'];
        yield 'service call with __invoke' => ['default', 'service', 'app.report_sender::__invoke', 'app-report_sender'];
        yield 'named schedule' => ['reports', 'class', '', 'reports-send-daily-report-message'];
        yield 'long slug' => ['default', 'command', 'app:a-very-long-command-name --with-many --options=enabled', 'app-a-very-long-command-name-with-many-op-4ff48cff'];
    }

    private function createTrigger(string $type, string $spec): TriggerInterface
    {
        switch ($type) {
            case 'cron':
                return CronExpressionTrigger::fromSpec($spec);
            case 'jitter':
                return new JitterTrigger(CronExpressionTrigger::fromSpec($spec));
            case 'every':
                return new PeriodicalTrigger(ctype_digit($spec) ? (int) $spec : $spec);
            default:
                return new CallbackTrigger(static function (\DateTimeImmutable $run): \DateTimeImmutable {
                    return $run->modify('+1 hour');
                });
        }
    }

    private function createContext(TriggerInterface $trigger, string $scheduleName = 'default'): MessageContext
    {
        $triggeredAt = $trigger->getNextRunDate(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $this->assertNotNull($triggeredAt);

        return new MessageContext($scheduleName, 'id', $trigger, $triggeredAt, $trigger->getNextRunDate($triggeredAt));
    }
}
