<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\MonitorScheduleUnit;
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
use Symfony\Component\Scheduler\Trigger\AbstractDecoratedTrigger;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\JitterTrigger;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Symfony\Component\Scheduler\Trigger\StaticMessageProvider;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sends cron check-ins for the recurring messages of the Symfony Scheduler.
 *
 * @internal
 */
final class SchedulerListener implements ResetInterface
{
    private const MAX_SLUG_LENGTH = 50;

    private const CRON_FIELD_MAXIMUMS = [59, 23, 31, 12, 7];

    // Sunday is left out because cron-expression counts it as 7 in ranges and cronsim as 0
    private const CRON_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
        'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    // Symfony 8.2+ adds this stamp to the messages that a worker sends to other transports instead of handling them
    private const REDISPATCH_STAMP = 'Symfony\Component\Messenger\Stamp\RedispatchStamp';

    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var array<string, array{slug: string, check_in_id: string, monitor_config: MonitorConfig, started_at: float}>
     */
    private $checkIns = [];

    /**
     * @var object|null
     */
    private $skippedMessage;

    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * Remembers scheduled messages that are retried or sent to another transport, so that they don't report a run.
     */
    public function handleWorkerMessageReceivedEvent(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $stamps = $envelope->all();
        $isSkipped = isset($stamps[ScheduledStamp::class]) && (isset($stamps[RedeliveryStamp::class]) || isset($stamps[self::REDISPATCH_STAMP]));

        $this->skippedMessage = $isSkipped ? $envelope->getMessage() : null;
    }

    public function handlePreRunEvent(PreRunEvent $event): void
    {
        $message = $event->getMessage();

        // A redispatched message is monitored when a worker consumes it from the other transport
        if ($event->shouldCancel() || $message === $this->skippedMessage || $message instanceof RedispatchMessage) {
            return;
        }

        try {
            $context = $event->getMessageContext();
            $schedule = $event->getSchedule()->getSchedule();
            $slug = $this->getMonitorSlug($schedule, $context, $message);

            if (null === $slug) {
                return;
            }

            $monitorConfig = self::createMonitorConfig($schedule, $context);

            if (null === $monitorConfig) {
                return;
            }

            $startedAt = microtime(true);
            $checkInId = $this->hub->captureCheckIn($slug, CheckInStatus::inProgress(), null, $monitorConfig);

            if (null !== $checkInId) {
                $this->checkIns[$this->getKey($context, $message)] = [
                    'slug' => $slug,
                    'check_in_id' => $checkInId,
                    'monitor_config' => $monitorConfig,
                    'started_at' => $startedAt,
                ];
            }
        } catch (\Throwable $exception) {
            $this->getLogger()->warning('Failed to send a cron check-in for a scheduled message.', ['exception' => $exception]);
        }
    }

    public function handlePostRunEvent(PostRunEvent $event): void
    {
        $this->finishCheckIn($event->getMessageContext(), $event->getMessage(), CheckInStatus::ok());
    }

    public function handleFailureEvent(FailureEvent $event): void
    {
        $this->finishCheckIn($event->getMessageContext(), $event->getMessage(), CheckInStatus::error());
    }

    public function reset(): void
    {
        $this->checkIns = [];
        $this->skippedMessage = null;
    }

    private function finishCheckIn(MessageContext $context, object $message, CheckInStatus $status): void
    {
        $key = $this->getKey($context, $message);

        if (!isset($this->checkIns[$key])) {
            return;
        }

        $checkIn = $this->checkIns[$key];
        unset($this->checkIns[$key]);

        try {
            $this->hub->captureCheckIn(
                $checkIn['slug'],
                $status,
                microtime(true) - $checkIn['started_at'],
                $checkIn['monitor_config'],
                $checkIn['check_in_id']
            );
        } catch (\Throwable $exception) {
            $this->getLogger()->warning('Failed to send a cron check-in for a scheduled message.', ['exception' => $exception]);
        }
    }

    private function getKey(MessageContext $context, object $message): string
    {
        return $context->name . '|' . $context->id . '|' . $context->triggeredAt->format('U.u') . '|' . spl_object_id($message);
    }

    /**
     * Returns null for triggers that Sentry can't express or would read
     * differently, so that their messages aren't monitored.
     */
    private static function createMonitorConfig(Schedule $schedule, MessageContext $context): ?MonitorConfig
    {
        $trigger = null;
        $jitterSeconds = 0;

        // The trigger of the context can't compute run dates once the message has crossed a transport
        foreach ($schedule->getRecurringMessages() as $recurringMessage) {
            if ($recurringMessage->getId() === $context->id) {
                $trigger = $recurringMessage->getTrigger();
            }
        }

        if ($trigger instanceof AbstractDecoratedTrigger) {
            foreach ($trigger->decorators() as $decorator) {
                // Other decorators, such as ExcludeTimeTrigger, skip runs that Sentry would expect
                if (!$decorator instanceof JitterTrigger || !preg_match('/ with 0-(\d+) second jitter$/', (string) $decorator, $matches)) {
                    return null;
                }

                $jitterSeconds += (int) $matches[1];
            }

            $trigger = $trigger->inner();
        }

        $checkinMargin = $jitterSeconds > 0 ? (int) ceil($jitterSeconds / 60) + 1 : null;

        if ($trigger instanceof CronExpressionTrigger) {
            $crontab = self::toCrontab((string) $trigger);
            $nextRun = null !== $crontab ? $trigger->getNextRunDate($context->triggeredAt) : null;
            $timezone = null !== $nextRun ? self::toTimezone($nextRun) : null;

            if (null === $crontab || null === $timezone) {
                return null;
            }

            return new MonitorConfig(MonitorSchedule::crontab($crontab), $checkinMargin, null, $timezone);
        }

        // Subclasses can compute other run dates
        if (null !== $trigger && PeriodicalTrigger::class === \get_class($trigger)) {
            $seconds = self::getIntervalInSeconds($trigger);

            if (null === $seconds || $seconds < 60 || 0.0 !== fmod($seconds, 60)) {
                return null;
            }

            $minutes = (int) ($seconds / 60);

            foreach ([[10080, MonitorScheduleUnit::week()], [1440, MonitorScheduleUnit::day()], [60, MonitorScheduleUnit::hour()]] as [$size, $unit]) {
                if (0 === $minutes % $size) {
                    return new MonitorConfig(MonitorSchedule::interval(intdiv($minutes, $size), $unit), $checkinMargin);
                }
            }

            return new MonitorConfig(MonitorSchedule::interval($minutes, MonitorScheduleUnit::minute()), $checkinMargin);
        }

        return null;
    }

    /**
     * Returns null for calendar intervals, such as "1 month", which have no fixed length.
     */
    private static function getIntervalInSeconds(PeriodicalTrigger $trigger): ?float
    {
        // PeriodicalTrigger has no getter for the interval. If the property changes, the message isn't monitored.
        try {
            $seconds = (new \ReflectionProperty(PeriodicalTrigger::class, 'intervalInSeconds'))->getValue($trigger);
        } catch (\ReflectionException $exception) {
            return null;
        }

        return \is_float($seconds) && $seconds > 0 ? $seconds : null;
    }

    private static function toCrontab(string $expression): ?string
    {
        // cron-expression reads "?" as "*"
        $fields = preg_split('/\s+/', trim(str_replace('?', '*', $expression)));

        if (false === $fields || 5 !== \count($fields)) {
            return null;
        }

        // When both day fields are set, cron-expression runs on either day, but cronsim needs both if one starts with "*"
        if ('*' !== $fields[2] && '*' !== $fields[4] && ('*' === $fields[2][0] || '*' === $fields[4][0])) {
            return null;
        }

        foreach ($fields as $position => $field) {
            foreach (explode(',', $field) as $item) {
                if (!self::isCronItemSupported($position, $item)) {
                    return null;
                }
            }
        }

        return implode(' ', $fields);
    }

    /**
     * Whether cronsim, which Sentry uses, reads the item the same way as dragonmantank/cron-expression.
     */
    private static function isCronItemSupported(int $position, string $item): bool
    {
        if ((2 === $position && 'L' === $item) || (4 === $position && preg_match('/^(?:\d(?:L|#[1-5])|[a-z]{3}#[1-5])$/i', $item))) {
            return true;
        }

        // cron-expression wraps steps that reach the end of the field around to another value
        if (preg_match('/^(\*|\d+-\d+)\/(\d+)$/', $item, $matches)) {
            $item = $matches[1];

            if ((int) $matches[2] >= self::CRON_FIELD_MAXIMUMS[$position]) {
                return false;
            }
        }

        if ('*' === $item || preg_match('/^(?:\d+|[a-z]{3})$/i', $item)) {
            return true;
        }

        if (!preg_match('/^(\d+|[a-z]{3})-(\d+|[a-z]{3})$/i', $item, $matches)) {
            return false;
        }

        $start = self::CRON_NAMES[strtoupper($matches[1])] ?? (is_numeric($matches[1]) ? (int) $matches[1] : null);
        $end = self::CRON_NAMES[strtoupper($matches[2])] ?? (is_numeric($matches[2]) ? (int) $matches[2] : null);

        // cronsim rejects ranges that end before they start
        return null !== $start && null !== $end && $start <= $end;
    }

    /**
     * Sentry needs a timezone identifier, so whole-hour offsets become Etc/GMT zones.
     */
    private static function toTimezone(\DateTimeImmutable $date): ?string
    {
        $timezone = $date->getTimezone();

        if (false !== $timezone->getLocation()) {
            return $timezone->getName();
        }

        $offset = $date->getOffset();

        if (0 !== $offset % 3600) {
            return null;
        }

        $hours = intdiv($offset, 3600);

        return 0 === $hours ? 'UTC' : \sprintf('Etc/GMT%+d', -$hours);
    }

    /**
     * Returns null when the message can't have a monitor of its own.
     */
    private function getMonitorSlug(Schedule $schedule, MessageContext $context, object $message): ?string
    {
        $slug = self::createSlug($context->name, $message);

        if (null === $slug) {
            return null;
        }

        $isStatic = false;
        $usages = 0;

        foreach ($schedule->getRecurringMessages() as $recurringMessage) {
            $provider = $recurringMessage->getProvider();

            if (!$provider instanceof StaticMessageProvider) {
                continue;
            }

            $isStatic = $isStatic || $recurringMessage->getId() === $context->id;

            foreach ($provider->getMessages($context) as $otherMessage) {
                if (self::createSlug($context->name, $otherMessage) === $slug) {
                    ++$usages;
                }
            }
        }

        // Other providers decide their messages when they run, so their slugs can't be checked against the schedule
        if (!$isStatic) {
            return null;
        }

        if ($usages > 1) {
            $this->getLogger()->warning(\sprintf('Not monitoring the scheduled messages with the slug "%s" because more than one recurring message uses it. Use a separate message class or schedule for each one.', $slug));

            return null;
        }

        return $slug;
    }

    private static function createSlug(string $scheduleName, object $message): ?string
    {
        $name = self::getMessageName($message);

        if (null === $name) {
            return null;
        }

        $slug = trim((string) preg_replace('/[^a-z0-9_]+/', '-', strtolower($scheduleName . '-' . $name)), '-');

        return rtrim(substr($slug, 0, self::MAX_SLUG_LENGTH), '-');
    }

    private static function getMessageName(object $message): ?string
    {
        if ($message instanceof RedispatchMessage) {
            $message = $message->envelope;
        }

        if ($message instanceof Envelope) {
            $message = $message->getMessage();
        }

        if ($message instanceof RunCommandMessage || $message instanceof ServiceCallMessage) {
            return (string) $message;
        }

        $class = new \ReflectionClass($message);

        // Anonymous class names contain the file path
        if ($class->isAnonymous()) {
            return null;
        }

        // SendDailyReportMessage becomes Send-Daily-Report-Message, and HTMLReport becomes HTML-Report
        return (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $class->getShortName());
    }

    private function getLogger(): LoggerInterface
    {
        $client = $this->hub->getClient();

        return null !== $client ? $client->getOptions()->getLoggerOrNullLogger() : new NullLogger();
    }
}
