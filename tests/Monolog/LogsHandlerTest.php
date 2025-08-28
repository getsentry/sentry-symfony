<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Monolog;

use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Monolog\LogsHandler;

final class LogsHandlerTest extends TestCase
{
    public function testHandleReturnsFalseBelowThresholdEvenWhenBubbleFalse(): void
    {
        $handler = new LogsHandler(MonologLogger::WARNING, false);
        $record = [
            'level' => MonologLogger::DEBUG,
            'message' => 'msg',
            'context' => [],
            'extra' => [],
        ];

        $this->assertFalse($handler->handle($record));
    }

    public function testHandleReturnsFalseAboveThresholdWhenBubbleTrue(): void
    {
        $handler = new LogsHandler(MonologLogger::DEBUG, true);
        $record = [
            'level' => MonologLogger::WARNING,
            'message' => 'msg',
            'context' => [],
            'extra' => [],
        ];

        $this->assertFalse($handler->handle($record));
    }

    public function testHandleReturnsTrueAboveThresholdWhenBubbleFalse(): void
    {
        $handler = new LogsHandler(MonologLogger::DEBUG, false);
        $record = [
            'level' => MonologLogger::WARNING,
            'message' => 'msg',
            'context' => [],
            'extra' => [],
        ];

        $this->assertTrue($handler->handle($record));
    }
}


