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

    /**
     * Crontab items that cronsim, which Sentry uses, reads the same way as dragonmantank/cron-expression.
     */
    private const CRON_ITEM_PATTERNS = [
        '/^(?:(?:\*|\d+(?:-\d+)?)(?:\/\d+)?|[a-z]{3}(?:-[a-z]{3})?)$/i',
        '/^L$/',
        '/^(?:\d(?:L|#[1-5])|[a-z]{3}#[1-5])$/i',
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
            $slug = $this->getMonitorSlug($event->getSchedule()->getSchedule(), $context, $message);

            if (null === $slug) {
                return;
            }

            $monitorConfig = $this->createMonitorConfig($context);

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
    private function createMonitorConfig(MessageContext $context): ?MonitorConfig
    {
        $trigger = $context->trigger;
        $jitterSeconds = 0;

        if ($trigger instanceof AbstractDecoratedTrigger) {
            foreach ($trigger->decorators() as $decorator) {
                // Other decorators, such as ExcludeTimeTrigger, skip runs that Sentry would expect
                if (!$decorator instanceof JitterTrigger) {
                    return null;
                }

                $maxSeconds = self::getPrivateProperty($decorator, JitterTrigger::class, 'maxSeconds');

                if (!\is_int($maxSeconds)) {
                    return null;
                }

                $jitterSeconds += $maxSeconds;
            }

            $trigger = $trigger->inner();
        }

        $checkinMargin = $jitterSeconds > 0 ? (int) ceil($jitterSeconds / 60) + 1 : null;

        if ($trigger instanceof CronExpressionTrigger) {
            $crontab = self::toCrontab((string) $trigger);
            $timezone = self::toTimezone($context->nextTriggerAt ?? $context->triggeredAt);

            if (null === $crontab || null === $timezone) {
                return null;
            }

            return new MonitorConfig(MonitorSchedule::crontab($crontab), $checkinMargin, null, $timezone);
        }

        if ($trigger instanceof PeriodicalTrigger) {
            // Zero for calendar intervals, such as a month
            $seconds = self::getPrivateProperty($trigger, PeriodicalTrigger::class, 'intervalInSeconds');

            if (!is_numeric($seconds) || $seconds < 60 || 0.0 !== fmod((float) $seconds, 60)) {
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
     * The triggers have no getters for these, so a renamed property stops monitoring.
     *
     * @return mixed
     */
    private static function getPrivateProperty(object $object, string $class, string $property)
    {
        try {
            return (new \ReflectionProperty($class, $property))->getValue($object);
        } catch (\ReflectionException $exception) {
            return null;
        }
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
                $isSpecial = (2 === $position && preg_match(self::CRON_ITEM_PATTERNS[1], $item)) || (4 === $position && preg_match(self::CRON_ITEM_PATTERNS[2], $item));

                if (!$isSpecial && !preg_match(self::CRON_ITEM_PATTERNS[0], $item)) {
                    return null;
                }
            }
        }

        return implode(' ', $fields);
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
