<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\MonitorScheduleUnit;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Component\Scheduler\Trigger\AbstractDecoratedTrigger;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

/**
 * Sends cron check-ins for messages dispatched by the Symfony Scheduler.
 *
 * The monitor slug is derived from the message: the command line for
 * RunCommandMessage (#[AsCronTask] on commands), the service and method for
 * ServiceCallMessage, otherwise the kebab-cased class name, followed by the
 * string representation of the message when it is stringable. Schedules other
 * than "default" prefix the slug with their name.
 *
 * @internal
 */
final class SchedulerListener
{
    private const MAX_SLUG_LENGTH = 50;

    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var array<string, array{slug: string, check_in_id: string, monitor_config: MonitorConfig, started_at: float}>
     */
    private $checkIns = [];

    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    public function handlePreRunEvent(PreRunEvent $event): void
    {
        if ($event->shouldCancel()) {
            return;
        }

        $context = $event->getMessageContext();
        $monitorConfig = $this->createMonitorConfig($context);

        // Without a schedule the check-in can't create the monitor, so there is nothing to report
        if (null === $monitorConfig) {
            return;
        }

        $slug = $this->createSlug($context, $event->getMessage());
        $checkInId = $this->hub->captureCheckIn($slug, CheckInStatus::inProgress(), null, $monitorConfig);

        if (null === $checkInId) {
            return;
        }

        $this->checkIns[$this->getKey($context, $event->getMessage())] = [
            'slug' => $slug,
            'check_in_id' => $checkInId,
            'monitor_config' => $monitorConfig,
            'started_at' => microtime(true),
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

        if ($trigger instanceof AbstractDecoratedTrigger) {
            $trigger = $trigger->inner();
        }

        if ($trigger instanceof CronExpressionTrigger) {
            $expression = (string) $trigger;

            if (5 !== \count(preg_split('/\s+/', trim($expression)) ?: [])) {
                return null;
            }

            // The trigger computes run dates in its own timezone, which is otherwise not exposed
            $timezone = $context->triggeredAt->getTimezone();

            return new MonitorConfig(
                MonitorSchedule::crontab($expression),
                null,
                null,
                false !== $timezone->getLocation() ? $timezone->getName() : null
            );
        }

        if ($trigger instanceof PeriodicalTrigger) {
            $schedule = $this->createIntervalSchedule($trigger);

            return null !== $schedule ? new MonitorConfig($schedule) : null;
        }

        return null;
    }

    private function createIntervalSchedule(PeriodicalTrigger $trigger): ?MonitorSchedule
    {
        // The interval is not exposed, and is zero for calendar based intervals (months, years)
        try {
            $seconds = (new \ReflectionProperty(PeriodicalTrigger::class, 'intervalInSeconds'))->getValue($trigger);
        } catch (\ReflectionException $e) {
            return null;
        }

        if (!\is_float($seconds) && !\is_int($seconds)) {
            return null;
        }

        $minutes = $seconds / 60;

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

    private function createSlug(MessageContext $context, object $message): string
    {
        if ($message instanceof RunCommandMessage || $message instanceof ServiceCallMessage) {
            $slug = (string) $message;
        } else {
            $className = \get_class($message);
            $shortName = substr($className, (int) strrpos('\\' . $className, '\\'));
            $slug = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '-', $shortName);

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
