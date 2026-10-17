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

    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var array<string, array{slug: string, check_in_id: string, monitor_config: MonitorConfig|null, started_at: float}>
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
     * Remembers scheduled messages that are retried or replayed, so that they don't report another run.
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

        // A redispatched message is monitored when a worker consumes it from the other transport
        if ($event->shouldCancel() || $message === $this->redeliveredMessage || $message instanceof RedispatchMessage) {
            return;
        }

        try {
            $context = $event->getMessageContext();
            $slug = $this->createSlug($context, $message);

            if (null === $slug) {
                return;
            }

            $monitorConfig = $this->createMonitorConfig($context);
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
     * Returns null, so that the check-ins carry no schedule, for triggers that
     * Sentry can't express or would read differently.
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

                $jitterSeconds += (new \ReflectionProperty(JitterTrigger::class, 'maxSeconds'))->getValue($decorator);
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
            $seconds = (new \ReflectionProperty(PeriodicalTrigger::class, 'intervalInSeconds'))->getValue($trigger);

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
     * The schedule name and a readable name of the message, followed by the
     * ID of the recurring message, which tells messages of the same class apart.
     */
    private function createSlug(MessageContext $context, object $message): ?string
    {
        $class = new \ReflectionClass($message);

        // Anonymous class names contain the file path
        if ($class->isAnonymous()) {
            return null;
        }

        if ($message instanceof RunCommandMessage || $message instanceof ServiceCallMessage) {
            $name = (string) $message;
        } else {
            // SendDailyReportMessage becomes Send-Daily-Report-Message, and HTMLReport becomes HTML-Report
            $name = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $class->getShortName());
        }

        $suffix = '-' . $context->id;
        $slug = trim((string) preg_replace('/[^a-z0-9_]+/', '-', strtolower($context->name . '-' . $name)), '-');

        return rtrim(substr($slug, 0, self::MAX_SLUG_LENGTH - \strlen($suffix)), '-') . $suffix;
    }

    private function logException(\Throwable $exception): void
    {
        $client = $this->hub->getClient();

        if (null !== $client) {
            $client->getOptions()->getLoggerOrNullLogger()->warning('Failed to send a cron check-in for a scheduled message.', ['exception' => $exception]);
        }
    }
}
