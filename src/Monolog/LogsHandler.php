<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monolog;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Sentry\Monolog\CompatibilityLogLevelTrait;
use Sentry\Monolog\LogsHandler as BaseLogsHandler;

/**
 * Wrapper for the Sentry LogsHandler that uses Monolog constants for initialization.
 * Sentry's LogLevel classes cannot be properly used in Symfony configuration so this acts as
 * a service facade that can be instantiated using yml/xml config files.
 */
class LogsHandler implements HandlerInterface
{
    use CompatibilityLogLevelTrait;

    /**
     * @var BaseLogsHandler
     */
    private $logsHandler;

    public function __construct(int $level = MonologLogger::DEBUG, bool $bubble = true)
    {
        $logLevel = self::getSentryLogLevelFromMonologLevel($level);
        $this->logsHandler = new BaseLogsHandler($logLevel, $bubble);
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     */
    public function isHandling(array $record): bool
    {
        return $this->logsHandler->isHandling($record);
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     */
    public function handle(array $record): bool
    {
        // Extra check required here because `isHandling` is not guaranteed to
        // be called, and we might accidentally capture log messages that should be filtered.
        if ($this->logsHandler->isHandling($record)) {
            return $this->logsHandler->handle($record);
        }

        return false;
    }

    /**
     * @param array<array<string, mixed>|LogRecord> $records
     */
    public function handleBatch(array $records): void
    {
        $this->logsHandler->handleBatch($records);
    }

    public function close(): void
    {
        $this->logsHandler->close();
    }

    /**
     * @param callable $callback
     */
    public function pushProcessor($callback): void
    {
        $this->logsHandler->pushProcessor($callback);
    }

    /**
     * @return callable
     */
    public function popProcessor()
    {
        return $this->logsHandler->popProcessor();
    }

    public function setFormatter(FormatterInterface $formatter): void
    {
        $this->logsHandler->setFormatter($formatter);
    }

    public function getFormatter(): FormatterInterface
    {
        return $this->logsHandler->getFormatter();
    }
}
