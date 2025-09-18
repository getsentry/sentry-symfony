<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Monolog;

use Monolog\Level as MonologLevel;
use Monolog\Logger as MonologLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel as PsrLogLevel;
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

    /**
     * @dataProvider levelProvider
     *
     * @param int|string|MonologLevel $level
     *
     * @phpstan-param value-of<MonologLevel::VALUES>|value-of<MonologLevel::NAMES>|MonologLevel|PsrLogLevel::* $level
     */
    public function testHandlerAcceptsVariousTypesAsLevel($level): void
    {
        $handler = new LogsHandler($level, false);
        $record = [
            'level' => MonologLogger::WARNING,
            'message' => 'msg',
            'context' => [],
            'extra' => [],
        ];

        $this->assertTrue($handler->handle($record));
    }

    /**
     * @return iterable<array{0: int|string|MonologLevel}>
     */
    public static function levelProvider(): iterable
    {
        yield [MonologLogger::DEBUG];
        yield ['DEBUG'];
        yield [PsrLogLevel::DEBUG];

        if (class_exists(MonologLevel::class)) {
            yield [MonologLevel::Debug];
        }
    }
}
