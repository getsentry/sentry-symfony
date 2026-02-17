<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Logs\Log;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithLogging;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @runTestsInSeparateProcesses
 */
class LoggingEnd2EndTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return KernelWithLogging::class;
    }

    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    public function testLoggerWithoutException(): void
    {
        $client = static::createClient(['debug' => true]);

        $client->request('GET', '/just-logging');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];
        $logs = $this->filterFrameworkLogs($event->getLogs());

        $this->assertCount(4, $logs);
    }

    public function testExceptionWithLogs(): void
    {
        $client = static::createClient(['debug' => true]);

        $client->request('GET', '/logging-with-error');
        $this->assertSame(500, $client->getResponse()->getStatusCode());

        // One is the exception, one is the event with logs
        $this->assertCount(2, StubTransport::$events);

        $exceptionEvent = StubTransport::$events[0];
        $this->assertCount(1, $exceptionEvent->getExceptions());
        $this->assertCount(0, $exceptionEvent->getLogs());
        $this->assertEquals('Crash', $exceptionEvent->getExceptions()[0]->getValue());

        $logsEvent = StubTransport::$events[1];
        $this->assertCount(0, $logsEvent->getExceptions());
        $this->assertCount(2, $logsEvent->getLogs());
    }

    public function testBeforeSendLogCallback(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/before-send-log');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];
        $logs = $this->filterFrameworkLogs($event->getLogs());

        // Make sure we just have two warn logs and no error log (since it's filtered by the callback)
        $this->assertCount(2, $logs);
        $this->assertEquals('warn 1', $logs[0]->getBody());
        $this->assertEquals('warn 2', $logs[1]->getBody());
    }

    /**
     * Removes framework logs so that the tests can focus on our expected logs.
     *
     * @param Log[] $logs
     *
     * @return Log[]
     */
    private function filterFrameworkLogs(array $logs): array
    {
        return array_filter($logs, static function (Log $log) {
            return 'Matched route "{route}".' !== $log->getBody()
                && 'Notified event "{event}" to listener "{listener}".' !== $log->getBody();
        });
    }
}
