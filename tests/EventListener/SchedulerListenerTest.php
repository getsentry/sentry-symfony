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
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
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

        $this->listener = new SchedulerListener($this->hub, ['app:excluded', '/^default-cleanup-/']);
    }

    public function testSuccessfulRun(): void
    {
        $context = $this->createContext(CronExpressionTrigger::fromSpec('0 3 * * *'));
        $message = new SendDailyReportMessage();

        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $context, $message));
        $this->listener->handlePostRunEvent(new PostRunEvent(new Schedule(), $context, $message));

        $this->assertCount(2, $this->checkIns);

        [$start, $finish] = $this->checkIns;

        $this->assertSame('default-send-daily-report-message-a1b2c3d4', $start['slug']);
        $this->assertSame(CheckInStatus::inProgress(), $start['status']);
        $this->assertNull($start['duration']);
        $this->assertNull($start['check_in_id']);

        $this->assertSame($start['slug'], $finish['slug']);
        $this->assertSame(CheckInStatus::ok(), $finish['status']);
        $this->assertIsFloat($finish['duration']);
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

    /**
     * @dataProvider excludedMessageDataProvider
     */
    public function testExcludedMessageIsNotReported(object $message, bool $isExcluded): void
    {
        $this->listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $this->createContext(CronExpressionTrigger::fromSpec('0 0 * * *')), $message));

        $this->assertCount($isExcluded ? 0 : 1, $this->checkIns);
    }

    /**
     * @return iterable<string, array{object, bool}>
     */
    public function excludedMessageDataProvider(): iterable
    {
        yield 'command name' => [new RunCommandMessage('app:excluded --force'), true];
        yield 'slug pattern' => [new RunCommandMessage('cleanup'), true];
        yield 'other command' => [new RunCommandMessage('app:included'), false];
        yield 'other message class' => [new SendDailyReportMessage(), false];
    }

    public function testExcludedMessageClassIsNotReported(): void
    {
        $listener = new SchedulerListener($this->hub, [SendDailyReportMessage::class]);
        $listener->handlePreRunEvent(new PreRunEvent(new Schedule(), $this->createContext(CronExpressionTrigger::fromSpec('0 0 * * *')), new SendDailyReportMessage()));

        $this->assertSame([], $this->checkIns);
    }

    private function createContext(TriggerInterface $trigger, string $scheduleName = 'default'): MessageContext
    {
        $triggeredAt = $trigger->getNextRunDate(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $this->assertNotNull($triggeredAt);

        return new MessageContext($scheduleName, 'a1b2c3d4', $trigger, $triggeredAt, $trigger->getNextRunDate($triggeredAt));
    }
}
