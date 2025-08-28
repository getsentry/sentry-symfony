<?php

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Logs\Log;
use Sentry\Logs\LogLevel;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithLogging;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

class LoggingCommandTest extends WebTestCase
{

    /**
     * @var Application
     */
    private $application;

    protected static function getKernelClass(): string
    {
        return KernelWithLogging::class;
    }

    protected function setUp(): void
    {
        StubTransport::$events = [];
        $this->application = new Application(self::bootKernel());
    }

    public function testLogsInCommand(): void
    {
        $this->application->doRun(new ArgvInput(['bin/console', "log:test"]), new NullOutput());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];
        $this->assertCount(2, $event->getLogs());

        $warnLog = $this->findOneByLevel($event->getLogs(), LogLevel::warn());
        $this->assertNotNull($warnLog);
        $this->assertEquals('Warn Log', $warnLog->getBody());

        $errorLog = $this->findOneByLevel($event->getLogs(), LogLevel::error());
        $this->assertNotNull($errorLog);
        $this->assertEquals('Error Log', $errorLog->getBody());
    }

    public function testExceptionLogsInCommand(): void
    {
        try {
            $this->application->doRun(new ArgvInput(['bin/console', "log:nosub"]), new NullOutput());
            $this->fail("Expected exception never occurred");
        } catch (\Throwable $t) {
        }

        // One is the exception, one is logs
        $this->assertCount(2, StubTransport::$events);

        $exceptionEvent = StubTransport::$events[0];
        $this->assertEmpty($exceptionEvent->getLogs());
        $this->assertCount(1, $exceptionEvent->getExceptions());
        $this->assertEquals('Crash in command', $exceptionEvent->getExceptions()[0]->getValue());

        $logEvent = StubTransport::$events[1];
        $this->assertCount(2, $logEvent->getLogs());
        $this->assertEmpty($logEvent->getExceptions());
    }

    public function testExceptionLogsWithSubcommand(): void
    {
        try {
            $this->application->doRun(new ArgvInput(['bin/console', "log:sub"]), new NullOutput());
            $this->fail("Expected exception never occurred");
        } catch (\Throwable $t) {

        }

        // 1.) Logs from subcommand
        // 2.) Exception
        // 3.) Logs from parent command
        $this->assertCount(3, StubTransport::$events);

        $logEvent = StubTransport::$events[0];
        // Because we flush logs when a command terminates, this contains the first log of the parent
        // command and the two logs from the subcommand.
        $this->assertCount(3, $logEvent->getLogs());
        $this->assertEmpty($logEvent->getExceptions());

        $exceptionEvent = StubTransport::$events[1];
        $this->assertEmpty($exceptionEvent->getLogs());
        $this->assertCount(1, $exceptionEvent->getExceptions());
        $this->assertEquals('Crash in command', $exceptionEvent->getExceptions()[0]->getValue());

        $logEvent = StubTransport::$events[2];
        // This contains the last log line from the parent command before crashing
        $this->assertCount(1, $logEvent->getLogs());
        $this->assertEmpty($logEvent->getExceptions());
    }

    private function findOneByLevel(array $logs, LogLevel $level): ?Log
    {
        foreach ($logs as $log) {
            if ($log->getLevel() === $level) {
                return $log;
            }
        }
        return null;
    }

}
