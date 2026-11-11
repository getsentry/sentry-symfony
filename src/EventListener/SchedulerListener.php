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
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
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
     * @var array<string, array{slug: string, check_in_id: string, started_at: float}>
     */
    private $checkIns = [];

    /**
     * @var object|null
     */
    private $skippedMessage;

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
     * Remembers scheduled messages that are retried or sent to another transport, so that they don't report a run.
     */
    public function handleWorkerMessageReceivedEvent(WorkerMessageReceivedEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $stamps = $envelope->all();
        $isSkipped = isset($stamps[ScheduledStamp::class]) && (isset($stamps[RedeliveryStamp::class]) || (isset($stamps[self::REDISPATCH_STAMP]) && $this->hasSenders($envelope)));

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

    private function hasSenders(Envelope $envelope): bool
    {
        if (null === $this->sendersLocator) {
            return true;
        }

        foreach ($this->sendersLocator->getSenders($envelope) as $sender) {
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
            $isPattern = \strlen($excluded) > 2 && '/' === $excluded[0] && '/' === substr($excluded, -1);

            foreach ($names as $name) {
                if ($isPattern ? 1 === @preg_match($excluded, $name) : $excluded === $name) {
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

    private function getLogger(): LoggerInterface
    {
        $client = $this->hub->getClient();

        return null !== $client ? $client->getOptions()->getLoggerOrNullLogger() : new NullLogger();
    }
}
