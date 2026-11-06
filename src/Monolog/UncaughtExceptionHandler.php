<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monolog;

use Monolog\Handler\AbstractHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Sentry\Monolog\ExceptionToSentryIssueHandler;
use Sentry\State\HubInterface;

final class UncaughtExceptionHandler extends AbstractHandler
{
    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var CapturedExceptionTracker
     */
    private $tracker;

    /**
     * @var bool
     */
    private $captureSoftFails;

    /**
     * @var int
     */
    private $minLevel;

    /**
     * @var ExceptionToSentryIssueHandler
     */
    private $exceptionHandler;

    public function __construct(HubInterface $hub, CapturedExceptionTracker $tracker, bool $captureSoftFails = true)
    {
        // Messages that will be retried are logged at WARNING level since Symfony 5.1
        $this->minLevel = $captureSoftFails ? Logger::WARNING : Logger::ERROR;

        parent::__construct($captureSoftFails ? 'warning' : 'error', true);

        $this->hub = $hub;
        $this->tracker = $tracker;
        $this->captureSoftFails = $captureSoftFails;
        $this->exceptionHandler = new ExceptionToSentryIssueHandler($hub, 'error');
    }

    /**
     * Called by Monolog for every record, also without calling isHandling() first (e.g. when the logger has no
     * processors), so everything up to the exception check must stay cheap.
     *
     * @param array{channel: string, level: int, context: array<string, mixed>}|LogRecord $record
     */
    public function handle($record): bool
    {
        if ($record instanceof LogRecord) {
            /** @psalm-suppress UndefinedPropertyFetch */
            $level = $record->level->value;
            /** @psalm-suppress UndefinedPropertyFetch */
            $context = $record->context;
        } else {
            $level = $record['level'];
            $context = $record['context'];
        }

        if ($level < $this->minLevel) {
            return false;
        }

        $error = $context['exception'] ?? null;

        if (!$error instanceof \Throwable || $this->tracker->isLastCaptured($error)) {
            return false;
        }

        // Messages that will be retried are logged with the retry delay, at ERROR level on Symfony < 5.1 and at
        // WARNING level since then
        if (\array_key_exists('delay', $context) && 'messenger' === $record['channel']) {
            if ($this->captureSoftFails) {
                // Captured directly, the delegate only handles records of at least ERROR level
                $this->tracker->setLastCaptured($error);
                $this->hub->captureException($error);
            }

            return false;
        }

        if ($level < Logger::ERROR) {
            return false;
        }

        $this->tracker->setLastCaptured($error);

        return $this->exceptionHandler->handle($record);
    }
}
