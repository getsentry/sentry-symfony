<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Sentry\CheckInStatus;
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
     * @var HubInterface
     */
    private $hub;

    /**
     * @var array<string, array{slug: string, check_in_id: string, started_at: float}>
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
            $slug = $this->getMonitorSlug($event->getSchedule()->getSchedule(), $context, $message);

            if (null === $slug) {
                return;
            }

            $startedAt = microtime(true);
            $checkInId = $this->hub->captureCheckIn($slug, CheckInStatus::inProgress());

            if (null !== $checkInId) {
                $this->checkIns[$this->getKey($context, $message)] = [
                    'slug' => $slug,
                    'check_in_id' => $checkInId,
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
                null,
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
