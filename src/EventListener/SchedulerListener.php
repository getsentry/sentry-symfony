<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\CheckInStatus;
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
    private $redeliveredMessage;

    /**
     * @param string[] $excludedMessages Message classes, command names or monitor slugs, or regular expressions wrapped in slashes
     */
    public function __construct(HubInterface $hub, array $excludedMessages = [])
    {
        $this->hub = $hub;
        $this->excludedMessages = $excludedMessages;
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

            if (null === $slug || $this->isExcluded($slug, $message)) {
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

    private function isExcluded(string $slug, object $message): bool
    {
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

    private function logException(\Throwable $exception): void
    {
        $client = $this->hub->getClient();

        if (null !== $client) {
            $client->getOptions()->getLoggerOrNullLogger()->warning('Failed to send a cron check-in for a scheduled message.', ['exception' => $exception]);
        }
    }
}
