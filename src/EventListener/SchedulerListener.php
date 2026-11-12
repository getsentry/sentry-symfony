<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Composer\InstalledVersions;
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
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
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

    private const MAX_STARTED_CHECK_INS = 100;

    private const CRON_FIELD_MAXIMUMS = [59, 23, 31, 12, 7];

    // Sunday is left out because cron-expression counts it as 7 in ranges and cronsim as 0
    private const CRON_NAMES = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
        'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    // Symfony 8.2+ adds this stamp to the messages that a worker sends to their senders, if they have any, instead of handling them
    private const REDISPATCH_STAMP = 'Symfony\Component\Messenger\Stamp\RedispatchStamp';

    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var SendersLocatorInterface|null
     */
    private $sendersLocator;

    /**
     * @var string[]
     */
    private $excludedMessages;

    /**
     * @var array<string, array{slug: string, check_in_id: string, monitor_config: MonitorConfig, started_at: float}>
     */
    private $checkIns = [];

    /**
     * @var PreRunEvent|null
     */
    private $preRunEvent;

    /**
     * Workers check every run, so each warning is logged once per process.
     *
     * @var array<string, true>
     */
    private $loggedWarnings = [];

    /**
     * @param string[] $excludedMessages Message classes, command names or monitor slugs, or regular expressions wrapped in slashes
     */
    public function __construct(HubInterface $hub, ?SendersLocatorInterface $sendersLocator = null, array $excludedMessages = [])
    {
        $this->hub = $hub;
        $this->sendersLocator = $sendersLocator;
        $this->excludedMessages = $excludedMessages;
    }

    /**
     * Keeps the run until the worker decides to handle the message, since later listeners can still cancel it.
     */
    public function handlePreRunEvent(PreRunEvent $event): void
    {
        $this->preRunEvent = $event;
    }

    /**
     * Sends the in-progress check-in of the run that the worker is about to handle.
     */
    public function handleWorkerMessageReceivedEvent(WorkerMessageReceivedEvent $event): void
    {
        $preRunEvent = $this->preRunEvent;
        $this->preRunEvent = null;
        $envelope = $event->getEnvelope();

        if (null === $preRunEvent || $preRunEvent->getMessage() !== $envelope->getMessage() || !$event->shouldHandle() || $preRunEvent->shouldCancel()) {
            return;
        }

        try {
            // A message sent to another transport is monitored when a worker consumes it there
            if ($this->isSentToAnotherTransport($envelope)) {
                return;
            }

            $this->startCheckIn($preRunEvent);
        } catch (\Throwable $exception) {
            $this->logException($exception);
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

    /**
     * Keeps the started check-ins, since batch handlers and concurrent workers finish runs after the services are reset.
     */
    public function reset(): void
    {
        $this->preRunEvent = null;
    }

    private function startCheckIn(PreRunEvent $event): void
    {
        $message = $event->getMessage();
        $context = $event->getMessageContext();
        $schedule = $event->getSchedule()->getSchedule();
        $slug = $this->getMonitorSlug($schedule, $context, $message);

        if (null === $slug) {
            return;
        }

        $monitorConfig = self::createMonitorConfig($schedule, $context);

        if (null === $monitorConfig) {
            $this->getLogger()->debug(\sprintf('Not monitoring the scheduled messages with the slug "%s" because Sentry can\'t express their schedule. Cron expressions need dragonmantank/cron-expression 3.3.0 or later.', $slug));

            return;
        }

        $startedAt = microtime(true);
        $checkInId = $this->hub->captureCheckIn($slug, CheckInStatus::inProgress(), null, $monitorConfig);

        if (null === $checkInId) {
            return;
        }

        // Runs that never finish, like the ones of a worker that gets killed, would otherwise stay forever
        if (\count($this->checkIns) >= self::MAX_STARTED_CHECK_INS) {
            array_shift($this->checkIns);
        }

        $this->checkIns[$this->getKey($context, $message)] = [
            'slug' => $slug,
            'check_in_id' => $checkInId,
            'monitor_config' => $monitorConfig,
            'started_at' => $startedAt,
        ];
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
            $this->logException($exception);
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

                // Jitter delays runs, but no check-in margin is sent since it would overwrite the one set in Sentry
                $jitterSeconds += (int) $matches[1];
            }

            $trigger = $trigger->inner();
        }

        if ($trigger instanceof CronExpressionTrigger) {
            $crontab = self::toCrontab((string) $trigger);
            $nextRun = null !== $crontab ? $trigger->getNextRunDate($context->triggeredAt) : null;
            $timezone = null !== $nextRun ? self::toTimezone($nextRun) : null;

            if (null === $crontab || null === $timezone) {
                return null;
            }

            return new MonitorConfig(MonitorSchedule::crontab($crontab), null, null, $timezone);
        }

        // Subclasses can compute other run dates
        if (null !== $trigger && PeriodicalTrigger::class === \get_class($trigger)) {
            $seconds = self::getProperty($trigger, 'intervalInSeconds');

            // Zero for calendar intervals, such as "1 day", which keep the time of day across DST changes
            if (0.0 === $seconds) {
                $interval = self::getProperty($trigger, 'interval');
                // Without a start, the trigger starts on its first run, which a worker that only consumes the message never makes
                $from = self::getProperty($trigger, 'from') ?? (0 === $jitterSeconds ? $context->triggeredAt : null);

                return $interval instanceof \DateInterval && $from instanceof \DateTimeImmutable ? self::createCalendarMonitorConfig($interval, $from, $context->triggeredAt, $jitterSeconds) : null;
            }

            if (!\is_float($seconds) || $seconds < 60 || 0.0 !== fmod($seconds, 60)) {
                return null;
            }

            $minutes = (int) ($seconds / 60);

            foreach ([[10080, MonitorScheduleUnit::week()], [1440, MonitorScheduleUnit::day()], [60, MonitorScheduleUnit::hour()]] as [$size, $unit]) {
                if (0 === $minutes % $size) {
                    return new MonitorConfig(MonitorSchedule::interval(intdiv($minutes, $size), $unit));
                }
            }

            return new MonitorConfig(MonitorSchedule::interval($minutes, MonitorScheduleUnit::minute()));
        }

        return null;
    }

    /**
     * Returns null for calendar intervals that neither a crontab nor a fixed interval can express.
     */
    private static function createCalendarMonitorConfig(\DateInterval $interval, \DateTimeImmutable $from, \DateTimeImmutable $triggeredAt, int $jitterSeconds): ?MonitorConfig
    {
        $calendarInterval = self::getCalendarInterval($interval);
        $timezone = self::toTimezone($from);

        if (null === $calendarInterval || null === $timezone) {
            return null;
        }

        [$count, $unit] = $calendarInterval;

        $run = $triggeredAt->setTimezone($from->getTimezone());
        $delay = 0 === $jitterSeconds ? 0 : (self::getSecondOfDay($run) - self::getSecondOfDay($from) + 86400) % 86400;
        $run = $run->setTimestamp($run->getTimestamp() - $delay);

        if ($delay > $jitterSeconds || ('day' === $unit && 0 === $count % 7 && $run->format('w') !== $from->format('w')) || ('month' === $unit && $run->format('j') !== $from->format('j'))) {
            return null;
        }

        // A run that falls in a DST gap moves to a later time, and the runs after it keep that time.
        // A jittered run can't show that, so its time comes from the start.
        $timeOfDay = 0 === $jitterSeconds ? $run : $from;
        $time = (int) $timeOfDay->format('i') . ' ' . $timeOfDay->format('G');
        $day = (int) $from->format('j');
        $crontab = null;

        if ('day' === $unit && 1 === $count) {
            $crontab = $time . ' * * *';
        } elseif ('day' === $unit && 7 === $count) {
            $crontab = $time . ' * * ' . $from->format('w');
        } elseif ('month' === $unit && 0 === 12 % $count && $day <= 28) {
            $months = [];

            for ($month = (int) $from->format('n') % $count ?: $count; $month <= 12; $month += $count) {
                $months[] = $month;
            }

            $crontab = $time . ' ' . $day . ' ' . (1 === $count ? '*' : implode(',', $months)) . ' *';
        }

        if (null !== $crontab) {
            return new MonitorConfig(MonitorSchedule::crontab($crontab), null, null, $timezone);
        }

        // Without DST, the days are all as long as Sentry expects
        if ('day' === $unit && ('UTC' === $timezone || str_starts_with($timezone, 'Etc/'))) {
            $schedule = 0 === $count % 7 ? MonitorSchedule::interval(intdiv($count, 7), MonitorScheduleUnit::week()) : MonitorSchedule::interval($count, MonitorScheduleUnit::day());

            return new MonitorConfig($schedule);
        }

        return null;
    }

    private static function getSecondOfDay(\DateTimeImmutable $date): int
    {
        return 3600 * (int) $date->format('G') + 60 * (int) $date->format('i') + (int) $date->format('s');
    }

    /**
     * Returns the number of days or months the interval adds, or null when it adds anything else.
     *
     * @return array{int, 'day'|'month'}|null
     */
    private static function getCalendarInterval(\DateInterval $interval): ?array
    {
        $fields = (array) $interval;

        // Since PHP 8.2, intervals made from a string only keep that string
        if (!empty($fields['from_string'])) {
            if (!\is_string($fields['date_string'] ?? null) || !preg_match('/^\s*\+?([1-9]\d*)\s*(day|week|month|year)s?\s*$/i', $fields['date_string'], $matches)) {
                return null;
            }

            $count = (int) $matches[1];
            $unit = strtolower($matches[2]);

            return 'week' === $unit ? [7 * $count, 'day'] : ('year' === $unit ? [12 * $count, 'month'] : [$count, 'day' === $unit ? 'day' : 'month']);
        }

        // Before PHP 8.2, these hold relative parts such as "first day of" or "next monday"
        foreach (['weekday', 'weekday_behavior', 'first_last_day_of', 'special_type', 'special_amount', 'have_weekday_relative', 'have_special_relative'] as $key) {
            if (!empty($fields[$key])) {
                return null;
            }
        }

        $months = 12 * $interval->y + $interval->m;

        if (1 === $interval->invert || 0 !== $interval->h || 0 !== $interval->i || 0 !== $interval->s || 0.0 !== $interval->f || ($months > 0) === ($interval->d > 0)) {
            return null;
        }

        return $months > 0 ? [$months, 'month'] : [$interval->d, 'day'];
    }

    /**
     * PeriodicalTrigger has no getters for its interval and start, so they're read from its
     * properties. If one changes, the message isn't monitored.
     *
     * @return mixed
     */
    private static function getProperty(PeriodicalTrigger $trigger, string $property)
    {
        try {
            return (new \ReflectionProperty(PeriodicalTrigger::class, $property))->getValue($trigger);
        } catch (\ReflectionException|\Error $exception) {
            return null;
        }
    }

    private static function toCrontab(string $expression): ?string
    {
        if (!self::isCronExpressionSupported()) {
            return null;
        }

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

            // cron-expression skips values of minute and hour lists that aren't in ascending order
            if ($position < 2 && str_contains($field, ',') && !self::isAscendingList($position, $field)) {
                return null;
            }
        }

        return implode(' ', $fields);
    }

    /**
     * Whether cron-expression is 3.3.0 or later, since older releases read more expressions, such as ones with "?", differently from cronsim.
     */
    private static function isCronExpressionSupported(): bool
    {
        if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('dragonmantank/cron-expression')) {
            return false;
        }

        $version = InstalledVersions::getVersion('dragonmantank/cron-expression');

        return null !== $version && version_compare($version, '3.3.0', '>=');
    }

    private static function isAscendingList(int $position, string $field): bool
    {
        $previous = -1;

        foreach (explode(',', $field) as $item) {
            // cron-expression releases order the minutes of lists with ranges or steps differently
            if (0 === $position && !ctype_digit($item)) {
                return false;
            }

            $step = 1;

            if (preg_match('/^(.+)\/(\d+)$/', $item, $matches)) {
                $item = $matches[1];
                $step = (int) $matches[2];
            }

            $bounds = '*' === $item ? [0, self::CRON_FIELD_MAXIMUMS[$position]] : array_map('intval', explode('-', $item));

            // range() throws when the step is longer than the range
            for ($value = $bounds[0], $end = $bounds[\count($bounds) - 1]; $value <= $end; $value += $step) {
                if ($value <= $previous) {
                    return false;
                }

                $previous = $value;
            }
        }

        return true;
    }

    /**
     * Whether cronsim, which Sentry uses, reads the item the same way as dragonmantank/cron-expression.
     */
    private static function isCronItemSupported(int $position, string $item): bool
    {
        if ((2 === $position && 'L' === $item) || (4 === $position && preg_match('/^(?:\d(?:L|#[1-5])|[a-z]{3}#[1-5])$/i', $item))) {
            return true;
        }

        $hasStep = false;

        // cron-expression wraps steps that reach the end of the field around to another value
        if (preg_match('/^(\*|\d+-\d+)\/(\d+)$/', $item, $matches)) {
            $item = $matches[1];
            $hasStep = true;

            if (0 === (int) $matches[2] || (int) $matches[2] >= self::CRON_FIELD_MAXIMUMS[$position]) {
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

        if (null === $start || null === $end) {
            return false;
        }

        // cron-expression and cronsim read these ranges differently
        if ($hasStep && $start === $end) {
            return false;
        }

        if (4 === $position && (($start === $end && \in_array($start, [0, 7], true)) || ($hasStep && 0 === $start && 7 === $end))) {
            return false;
        }

        // cronsim rejects ranges that end before they start
        return $start <= $end;
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

        if (null === $slug || $this->isExcluded($slug, $message)) {
            return null;
        }

        $isInSchedule = false;
        $isStatic = false;
        $usages = 0;

        foreach ($schedule->getRecurringMessages() as $recurringMessage) {
            $provider = $recurringMessage->getProvider();

            if ($recurringMessage->getId() === $context->id) {
                $isInSchedule = true;
                $isStatic = $provider instanceof StaticMessageProvider;
            }

            if (!$provider instanceof StaticMessageProvider) {
                continue;
            }

            foreach ($provider->getMessages($context) as $otherMessage) {
                if (self::createSlug($context->name, $otherMessage) === $slug && !$this->isExcluded($slug, $otherMessage)) {
                    ++$usages;
                }
            }
        }

        if (!$isInSchedule) {
            return null;
        }

        // Other providers decide their messages when they run, so they're only checked against the static ones
        if ($usages > ($isStatic ? 1 : 0)) {
            $this->warnOnce('shared|' . $slug, \sprintf('Not monitoring the scheduled messages with the slug "%s" because more than one recurring message uses it. Use a separate message class or schedule for each one.', $slug));

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
        $message = self::unwrap($message);

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

    private function isSentToAnotherTransport(Envelope $envelope): bool
    {
        $message = $envelope->getMessage();
        $name = self::getMessageName($message) ?? \get_class($message);

        // Symfony 6.4 to 8.1 handle a RedispatchMessage by dispatching its message to the given transports
        if ($message instanceof RedispatchMessage) {
            return $this->hasSenders(Envelope::wrap($message->envelope, [new TransportNamesStamp($message->transportNames)]), $name);
        }

        return \array_key_exists(self::REDISPATCH_STAMP, $envelope->all()) && $this->hasSenders($envelope, $name);
    }

    /**
     * Whether a transport other than the sync one, which handles the message in the same run, takes the message.
     */
    private function hasSenders(Envelope $envelope, string $messageName): bool
    {
        if (null === $this->sendersLocator) {
            return true;
        }

        try {
            foreach ($this->sendersLocator->getSenders($envelope) as $sender) {
                if (!$sender instanceof SyncTransport) {
                    return true;
                }
            }
        } catch (\Throwable $exception) {
            // Such as a transport name that doesn't exist, which fails the run anyway
            $this->warnOnce('transport|' . $messageName, 'Failed to send a cron check-in for a scheduled message.', ['exception' => $exception]);

            return true;
        }

        return false;
    }

    private function isExcluded(string $slug, object $message): bool
    {
        $message = self::unwrap($message);
        $names = [$slug, \get_class($message)];

        if ($message instanceof RunCommandMessage) {
            $names[] = (string) $message;
            $names[] = explode(' ', trim((string) $message))[0];
        } elseif ($message instanceof ServiceCallMessage) {
            $names[] = (string) $message;
        }

        foreach ($this->excludedMessages as $excluded) {
            if (!preg_match('/^\/.+\/[a-zA-Z]*$/', $excluded)) {
                if (\in_array(ltrim($excluded, '\\'), $names, true)) {
                    return true;
                }

                continue;
            }

            foreach ($names as $name) {
                // Without the @, PHP raises a warning for an invalid pattern
                $result = @preg_match($excluded, $name);

                if (false === $result) {
                    $this->warnOnce('pattern|' . $excluded, \sprintf('Ignoring the invalid regular expression "%s" in the excluded messages of the scheduler.', $excluded));

                    continue 2;
                }

                if (1 === $result) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function unwrap(object $message): object
    {
        if ($message instanceof RedispatchMessage) {
            $message = $message->envelope;
        }

        return $message instanceof Envelope ? $message->getMessage() : $message;
    }

    private function logException(\Throwable $exception): void
    {
        $this->getLogger()->warning('Failed to send a cron check-in for a scheduled message.', ['exception' => $exception]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warnOnce(string $key, string $message, array $context = []): void
    {
        if (isset($this->loggedWarnings[$key])) {
            return;
        }

        $this->loggedWarnings[$key] = true;
        $this->getLogger()->warning($message, $context);
    }

    private function getLogger(): LoggerInterface
    {
        $client = $this->hub->getClient();

        return null !== $client ? $client->getOptions()->getLoggerOrNullLogger() : new NullLogger();
    }
}
