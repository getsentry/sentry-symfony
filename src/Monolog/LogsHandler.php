<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monolog;

use Monolog\Level as MonologLevel;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel as PsrLogLevel;
use Sentry\Monolog\CompatibilityLogLevelTrait;
use Sentry\Monolog\LogsHandler as BaseLogsHandler;

/**
 * Extends the base LogsHandler so that it can be used with Monolog constants.
 * Sentry LogLevel objects are not easily usable with symfony config files, so this provides
 * a convenient way to instantiate the service in yaml/xml.
 */
class LogsHandler extends BaseLogsHandler
{
    use CompatibilityLogLevelTrait;

    /**
     * @param int|string|MonologLevel|PsrLogLevel::* $level
     *
     * @phpstan-param value-of<MonologLevel::VALUES>|value-of<MonologLevel::NAMES>|MonologLevel|PsrLogLevel::* $level
     */
    public function __construct($level = MonologLogger::DEBUG, bool $bubble = true)
    {
        try {
            $level = MonologLogger::toMonologLevel($level);
        } catch (InvalidArgumentException $e) {
            $level = MonologLogger::INFO;
        }
        if ($level instanceof MonologLevel) { // Monolog >= 3
            $level = $level->value;
        }
        $logLevel = self::getSentryLogLevelFromMonologLevel($level);
        parent::__construct($logLevel, $bubble);
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     */
    public function handle($record): bool
    {
        // Extra check required here because `isHandling` is not guaranteed to
        // be called, and we might accidentally capture log messages that should be filtered.
        if ($this->isHandling($record)) {
            return parent::handle($record);
        }

        return false;
    }
}
