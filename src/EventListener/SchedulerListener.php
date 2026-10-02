<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\MonitorScheduleUnit;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Scheduler\Trigger\AbstractDecoratedTrigger;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\JitterTrigger;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sends cron check-ins for messages dispatched by the Symfony Scheduler.
 *
 * The monitor slug is derived from the message: the command line for
 * RunCommandMessage (#[AsCronTask] on commands), the service and method for
 * ServiceCallMessage, otherwise the kebab-cased class name, followed by the
 * string representation of the message when it is stringable. Schedules other
 * than "default" prefix the slug with their name. Messages of the same class
 * share a monitor unless they implement __toString() to tell them apart.
 *
 * Not monitored: RedispatchMessage (the redispatched message is monitored when
 * a worker consumes it), redelivered messages, anonymous message classes, and
 * triggers other than cron and whole-minute intervals, optionally with jitter.
 *
 * @internal
 */
final class SchedulerListener implements ResetInterface
{
    private const MAX_SLUG_LENGTH = 50;

    /**
     * Value ranges of the crontab fields: minute, hour, day of month, month and day of week.
     */
    private const CRON_FIELD_RANGES = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 7]];

    private const CRON_NAMES = [
        3 => ['JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12],
        4 => ['SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6],
    ];

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
    private $redeliveredMessage;

    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * Remembers scheduled messages that are being retried or replayed from a
     * failure transport, so that they don't report another run.
     */
    public function handleWorkerMessageReceivedEvent(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $isRedelivered = null !== $envelope->last(ScheduledStamp::class) && null !== $envelope->last(RedeliveryStamp::class);

        $this->redeliveredMessage = $isRedelivered ? $envelope->getMessage() : null;
    }

    public function handlePreRunEvent(PreRunEvent $event): void
    {
        $message = $event->getMessage();

        if ($event->shouldCancel() || $message === $this->redeliveredMessage) {
            return;
        }

        // Only the hand-off to another transport would be measured; the redispatched
        // message carries the schedule and is monitored when a worker consumes it
        if ($message instanceof RedispatchMessage) {
            return;
        }

        $context = $event->getMessageContext();
        $monitorConfig = $this->createMonitorConfig($context);

        // Without a schedule the check-in can't create the monitor, so there is nothing to report
        if (null === $monitorConfig) {
            return;
        }

        $slug = $this->createSlug($context, $message);

        if (null === $slug) {
            return;
        }

        $startedAt = microtime(true);
        $checkInId = $this->hub->captureCheckIn($slug, CheckInStatus::inProgress(), null, $monitorConfig);

        if (null === $checkInId) {
            return;
        }

        $this->checkIns[$this->getKey($context, $message)] = [
            'slug' => $slug,
            'check_in_id' => $checkInId,
            'monitor_config' => $monitorConfig,
            'started_at' => $startedAt,
        ];
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
        $this->redeliveredMessage = null;
    }

    private function finishCheckIn(MessageContext $context, object $message, CheckInStatus $status): void
    {
        $key = $this->getKey($context, $message);

        if (!isset($this->checkIns[$key])) {
            return;
        }

        $checkIn = $this->checkIns[$key];
        unset($this->checkIns[$key]);

        $this->hub->captureCheckIn(
            $checkIn['slug'],
            $status,
            microtime(true) - $checkIn['started_at'],
            $checkIn['monitor_config'],
            $checkIn['check_in_id']
        );
    }

    private function getKey(MessageContext $context, object $message): string
    {
        return $context->name . '|' . $context->id . '|' . $context->triggeredAt->format('U.u') . '|' . spl_object_id($message);
    }

    private function createMonitorConfig(MessageContext $context): ?MonitorConfig
    {
        $trigger = $context->trigger;
        $jitterSeconds = 0;

        if ($trigger instanceof AbstractDecoratedTrigger) {
            foreach ($trigger->decorators() as $decorator) {
                $maxSeconds = $decorator instanceof JitterTrigger ? $this->getJitterSeconds($decorator) : null;

                // Other decorators, such as ExcludeTimeTrigger, skip runs that Sentry would expect
                if (null === $maxSeconds) {
                    return null;
                }

                $jitterSeconds += $maxSeconds;
            }

            $trigger = $trigger->inner();
        }

        // Runs can start late by up to the jitter, so allow for it on top of the default margin of a minute
        $checkinMargin = $jitterSeconds > 0 ? (int) ceil($jitterSeconds / 60) + 1 : null;

        if ($trigger instanceof CronExpressionTrigger) {
            $expression = (string) $trigger;

            if (!self::isSupportedCronExpression($expression)) {
                return null;
            }

            // The trigger computes run dates in its own timezone, which is otherwise not exposed
            $timezone = $context->triggeredAt->getTimezone();

            return new MonitorConfig(
                MonitorSchedule::crontab($expression),
                $checkinMargin,
                null,
                false !== $timezone->getLocation() ? $timezone->getName() : null
            );
        }

        if ($trigger instanceof PeriodicalTrigger) {
            $schedule = $this->createIntervalSchedule($trigger);

            return null !== $schedule ? new MonitorConfig($schedule, $checkinMargin) : null;
        }

        return null;
    }

    private function getJitterSeconds(JitterTrigger $trigger): ?int
    {
        try {
            $maxSeconds = (new \ReflectionProperty(JitterTrigger::class, 'maxSeconds'))->getValue($trigger);
        } catch (\ReflectionException $e) {
            return null;
        }

        return \is_int($maxSeconds) ? $maxSeconds : null;
    }

    private function createIntervalSchedule(PeriodicalTrigger $trigger): ?MonitorSchedule
    {
        // The interval is not exposed, and is zero for calendar based intervals (months, years)
        try {
            $seconds = (new \ReflectionProperty(PeriodicalTrigger::class, 'intervalInSeconds'))->getValue($trigger);
        } catch (\ReflectionException $e) {
            return null;
        }

        if (!\is_int($seconds) && !\is_float($seconds)) {
            return null;
        }

        $minutes = (float) $seconds / 60;

        if ($minutes < 1 || floor($minutes) !== $minutes) {
            return null;
        }

        $minutes = (int) $minutes;

        foreach ([[10080, MonitorScheduleUnit::week()], [1440, MonitorScheduleUnit::day()], [60, MonitorScheduleUnit::hour()]] as [$size, $unit]) {
            if (0 === $minutes % $size) {
                return MonitorSchedule::interval(intdiv($minutes, $size), $unit);
            }
        }

        return MonitorSchedule::interval($minutes, MonitorScheduleUnit::minute());
    }

    /**
     * Checks that Sentry can parse the expression: five fields of values, names,
     * ascending ranges and steps, plus "L" and "LW" as the day of month and "nL"
     * and "n#k" as the day of week. Sentry rejects "W" and "?", and wrapping ranges.
     */
    private static function isSupportedCronExpression(string $expression): bool
    {
        $fields = preg_split('/\s+/', trim($expression));

        if (false === $fields || 5 !== \count($fields)) {
            return false;
        }

        foreach ($fields as $position => $field) {
            foreach (explode(',', strtoupper($field)) as $item) {
                if (!self::isSupportedCronItem($position, $item)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function isSupportedCronItem(int $position, string $item): bool
    {
        if (2 === $position && ('L' === $item || 'LW' === $item)) {
            return true;
        }

        if (4 === $position && preg_match('/^(\w+)(?:L|#[1-5])$/', $item, $matches)) {
            return null !== self::parseCronValue($position, $matches[1]);
        }

        if (!preg_match('/^(\*|\w+(?:-\w+)?)(?:\/(\d+))?$/', $item, $matches)) {
            return false;
        }

        if (isset($matches[2]) && (int) $matches[2] < 1) {
            return false;
        }

        if ('*' === $matches[1]) {
            return true;
        }

        $bounds = explode('-', $matches[1]);
        $start = self::parseCronValue($position, $bounds[0]);
        $end = self::parseCronValue($position, $bounds[1] ?? $bounds[0]);

        return null !== $start && null !== $end && $start <= $end;
    }

    private static function parseCronValue(int $position, string $value): ?int
    {
        if (isset(self::CRON_NAMES[$position][$value])) {
            return self::CRON_NAMES[$position][$value];
        }

        if (!ctype_digit($value)) {
            return null;
        }

        [$min, $max] = self::CRON_FIELD_RANGES[$position];

        return (int) $value >= $min && (int) $value <= $max ? (int) $value : null;
    }

    private function createSlug(MessageContext $context, object $message): ?string
    {
        if ($message instanceof RunCommandMessage || $message instanceof ServiceCallMessage) {
            $slug = (string) $message;
        } else {
            $class = new \ReflectionClass($message);

            // Anonymous class names contain the file path, which is not a stable slug
            if ($class->isAnonymous()) {
                return null;
            }

            // SendDailyReportMessage becomes Send-Daily-Report-Message, and HTMLReport becomes HTML-Report
            $slug = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $class->getShortName());

            if (method_exists($message, '__toString')) {
                $slug .= '-' . $message->__toString();
            }
        }

        if ('default' !== $context->name) {
            $slug = $context->name . '-' . $slug;
        }

        $slug = trim((string) preg_replace('/[^a-z0-9_]+/', '-', strtolower($slug)), '-');

        if (\strlen($slug) > self::MAX_SLUG_LENGTH) {
            // Sentry truncates longer slugs, so keep them distinct with a short hash
            $slug = rtrim(substr($slug, 0, self::MAX_SLUG_LENGTH - 9), '-') . '-' . substr(sha1($slug), 0, 8);
        }

        return $slug;
    }
}
