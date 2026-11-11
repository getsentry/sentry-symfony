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
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
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
     * @var SchedulerListener
     */
    private $listener;

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

                return $checkInId ?? 'check-in-id';
            });

        $this->listener = new SchedulerListener($this->hub);
    }

    public function testSuccessfulRun(): void
    {
        $message = new SendDailyReportMessage();
        $recurringMessage = RecurringMessage::cron('0 3 * * *', $message);
        $schedule = (new Schedule())->add($recurringMessage);
        $context = $this->createContext($recurringMessage);

        $this->listener->handlePreRunEvent(new PreRunEvent($schedule, $context, $message));
        $this->listener->handlePostRunEvent(new PostRunEvent($schedule, $context, $message));

        $this->assertCount(2, $this->checkIns);

        [$start, $finish] = $this->checkIns;

        $this->assertSame('default-send-daily-report-message', $start['slug']);
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
        $message = new SendDailyReportMessage();
        $recurringMessage = RecurringMessage::cron('*/5 * * * *', $message);
        $schedule = (new Schedule())->add($recurringMessage);
        $context = $this->createContext($recurringMessage);

        $this->listener->handlePreRunEvent(new PreRunEvent($schedule, $context, $message));
        $this->listener->handleFailureEvent(new FailureEvent($schedule, $context, $message, new \RuntimeException()));

        $this->assertCount(2, $this->checkIns);
        $this->assertSame(CheckInStatus::error(), $this->checkIns[1]['status']);
        $this->assertSame('check-in-id', $this->checkIns[1]['check_in_id']);
    }

    public function testCancelledRunIsNotReported(): void
    {
        $message = new SendDailyReportMessage();
        $recurringMessage = RecurringMessage::cron('*/5 * * * *', $message);
        $event = new PreRunEvent((new Schedule())->add($recurringMessage), $this->createContext($recurringMessage), $message);
        $event->shouldCancel(true);

        $this->listener->handlePreRunEvent($event);

        $this->assertSame([], $this->checkIns);
    }

    public function testFinishWithoutStartIsIgnored(): void
    {
        $message = new SendDailyReportMessage();
        $recurringMessage = RecurringMessage::cron('*/5 * * * *', $message);

        $this->listener->handlePostRunEvent(new PostRunEvent((new Schedule())->add($recurringMessage), $this->createContext($recurringMessage), $message));

        $this->assertSame([], $this->checkIns);
    }

    public function testResetDropsStartedCheckIns(): void
    {
        $message = new SendDailyReportMessage();
        $recurringMessage = RecurringMessage::cron('*/5 * * * *', $message);
        $schedule = (new Schedule())->add($recurringMessage);
        $context = $this->createContext($recurringMessage);

        $this->listener->handlePreRunEvent(new PreRunEvent($schedule, $context, $message));
        $this->listener->reset();
        $this->listener->handlePostRunEvent(new PostRunEvent($schedule, $context, $message));

        $this->assertCount(1, $this->checkIns);
    }

    public function testRedispatchMessageIsNotReported(): void
    {
        $recurringMessage = RecurringMessage::cron('*/5 * * * *', new RedispatchMessage(new SendDailyReportMessage(), 'async'));

        $this->runPreRunEvents($recurringMessage);

        $this->assertSame([], $this->checkIns);
    }

    public function testRedispatchedMessageIsReportedWhenConsumed(): void
    {
        $message = new SendDailyReportMessage();
        $recurringMessage = RecurringMessage::cron('0 * * * *', new RedispatchMessage($message, 'async'));
        $context = $this->createContext($recurringMessage);

        $this->listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent(new Envelope($message, [new ScheduledStamp($context)]), 'async'));
        $this->listener->handlePreRunEvent(new PreRunEvent((new Schedule())->add($recurringMessage), $context, $message));

        $this->assertSame(['default-send-daily-report-message'], array_column($this->checkIns, 'slug'));
    }

    public function testMessageSentToAnotherTransportIsNotReported(): void
    {
        $redispatchStampClass = 'Symfony\Component\Messenger\Stamp\RedispatchStamp';

        if (!class_exists($redispatchStampClass)) {
            $this->markTestSkipped('This test requires the "symfony/messenger" Composer package (8.2 or newer) to be installed.');
        }

        $message = new SendDailyReportMessage();
        $recurringMessage = RecurringMessage::cron('0 * * * *', new RedispatchMessage($message, 'async'));
        $context = $this->createContext($recurringMessage);
        $redispatchStamp = new $redispatchStampClass();
        $this->assertInstanceOf(StampInterface::class, $redispatchStamp);

        $this->listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent(new Envelope($message, [new ScheduledStamp($context), $redispatchStamp]), 'scheduler_default'));
        $this->listener->handlePreRunEvent(new PreRunEvent((new Schedule())->add($recurringMessage), $context, $message));

        $this->assertSame([], $this->checkIns);
    }

    public function testAnonymousMessageIsNotReported(): void
    {
        $this->runPreRunEvents(RecurringMessage::cron('*/5 * * * *', new StaticMessageProvider([new class {
        }], 'anonymous')));

        $this->assertSame([], $this->checkIns);
    }

    public function testRedeliveredMessageIsNotReported(): void
    {
        $message = new SendDailyReportMessage();
        $recurringMessage = RecurringMessage::cron('*/5 * * * *', $message);
        $schedule = (new Schedule())->add($recurringMessage);
        $context = $this->createContext($recurringMessage);

        $this->listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent(new Envelope($message, [new ScheduledStamp($context), new RedeliveryStamp(1)]), 'async'));
        $this->listener->handlePreRunEvent(new PreRunEvent($schedule, $context, $message));

        $this->assertCount(0, $this->checkIns);

        $this->listener->handleWorkerMessageReceivedEvent(new WorkerMessageReceivedEvent(new Envelope($message, [new ScheduledStamp($context)]), 'async'));
        $this->listener->handlePreRunEvent(new PreRunEvent($schedule, $context, $message));

        $this->assertCount(1, $this->checkIns);
    }

    public function testFailingCheckInIsLoggedAndDoesNotThrow(): void
    {
        $this->logger->expects($this->once())->method('warning')->with('Failed to send a cron check-in for a scheduled message.');

        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturn(new Options(['logger' => $this->logger]));

        $hub = $this->createMock(HubInterface::class);
        $hub->method('getClient')->willReturn($client);
        $hub->method('captureCheckIn')->willThrowException(new \RuntimeException());

        $this->listener = new SchedulerListener($hub);
        $this->runPreRunEvents(RecurringMessage::cron('*/5 * * * *', new SendDailyReportMessage()));
    }

    /**
     * @dataProvider slugDataProvider
     */
    public function testSlug(string $scheduleName, callable $createMessage, string $expectedSlug): void
    {
        $this->runPreRunEvents(RecurringMessage::cron('0 0 * * *', $createMessage()), null, $scheduleName);

        $this->assertCount(1, $this->checkIns);
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
        $this->logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(\sprintf('Not monitoring the scheduled messages with the slug "%s" because more than one recurring message uses it. Use a separate message class or schedule for each one.', $sharedSlug));

        $recurringMessages = [
            RecurringMessage::cron('0 * * * *', $createFirst()),
            RecurringMessage::cron('0 0 * * *', $createSecond()),
            RecurringMessage::cron('0 0 * * *', new RunCommandMessage('app:other')),
        ];
        $schedule = (new Schedule())->add(...$recurringMessages);

        foreach ($recurringMessages as $recurringMessage) {
            $this->runPreRunEvents($recurringMessage, $schedule);
        }

        $this->assertSame(['default-app-other'], array_column($this->checkIns, 'slug'));
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
        $this->runPreRunEvents(RecurringMessage::cron('0 * * * *', new CallbackMessageProvider(static function (): iterable {
            return [new SendDailyReportMessage()];
        }, 'reports')));

        $this->assertSame(['default-send-daily-report-message'], array_column($this->checkIns, 'slug'));
    }

    public function testMessageOfAnotherProviderThatSharesASlugIsNotReported(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Not monitoring the scheduled messages with the slug "default-send-daily-report-message" because more than one recurring message uses it. Use a separate message class or schedule for each one.');

        $static = RecurringMessage::cron('0 0 * * *', new SendDailyReportMessage());
        $callback = RecurringMessage::cron('0 * * * *', new CallbackMessageProvider(static function (): iterable {
            return [new SendDailyReportMessage(), new RunCommandMessage('app:other')];
        }, 'reports'));
        $schedule = (new Schedule())->add($static, $callback);

        $this->runPreRunEvents($callback, $schedule);

        $this->assertSame(['default-app-other'], array_column($this->checkIns, 'slug'));
    }

    public function testMessageMissingFromTheScheduleIsNotReported(): void
    {
        $this->runPreRunEvents(RecurringMessage::cron('0 * * * *', new SendDailyReportMessage()), new Schedule());

        $this->assertSame([], $this->checkIns);
    }

    private function runPreRunEvents(RecurringMessage $recurringMessage, ?Schedule $schedule = null, string $scheduleName = 'default'): void
    {
        $context = $this->createContext($recurringMessage, $scheduleName);

        foreach ($recurringMessage->getMessages($context) as $message) {
            $message = $message instanceof Envelope ? $message->getMessage() : $message;
            $this->listener->handlePreRunEvent(new PreRunEvent($schedule ?? (new Schedule())->add($recurringMessage), $context, $message));
        }
    }

    private function createContext(RecurringMessage $recurringMessage, string $scheduleName = 'default'): MessageContext
    {
        $trigger = $recurringMessage->getTrigger();
        $triggeredAt = $trigger->getNextRunDate(new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')));
        $this->assertNotNull($triggeredAt);

        return new MessageContext($scheduleName, $recurringMessage->getId(), $trigger, $triggeredAt, $trigger->getNextRunDate($triggeredAt));
    }
}
