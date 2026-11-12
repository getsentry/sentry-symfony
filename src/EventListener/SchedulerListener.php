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
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sync\SyncTransport;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
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

    private const MAX_STARTED_CHECK_INS = 100;

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
     * @var PreRunEvent|null
     */
    private $preRunEvent;

    /**
     * Workers check every run, so each warning is logged once per slug or message per process.
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
            // Retries aren't reported, and a message sent to another transport is monitored when a worker consumes it there
            if (null !== $envelope->last(RedeliveryStamp::class) || $this->isSentToAnotherTransport($envelope)) {
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
        $slug = $this->getMonitorSlug($event->getSchedule()->getSchedule(), $context, $message);

        if (null === $slug) {
            return;
        }

        $startedAt = microtime(true);
        $checkInId = $this->hub->captureCheckIn($slug, CheckInStatus::inProgress());

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
                null,
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
                    $this->getLogger()->warning(\sprintf('Ignoring the invalid regular expression "%s" in the excluded messages of the scheduler.', $excluded));

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
