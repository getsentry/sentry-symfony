<?php

declare(strict_types=1);

namespace Command;

use Monolog\Handler\BufferHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sentry\Client;
use Sentry\Monolog\BreadcrumbHandler;
use Sentry\Monolog\Handler;
use Sentry\Options;
use Sentry\SentryBundle\Command\SentryBreadcrumbTestCommand;
use Sentry\SentryBundle\Command\SentryDummyTestCommand;
use Sentry\SentryBundle\Command\SentrySubcommandTestCommand;
use Sentry\SentryBundle\EventListener\BufferFlusher;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\SentryBundle\Tests\End2End\StubTransport;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests in this suite test the following configuration:
 * - Sentry breadcrumb handler is enabled
 * - Sentry Monolog handler is enabled which will send log lines with a severity of warn or above to sentry.
 */
class BreadcrumbTestCommandTest extends TestCase
{
    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Application
     */
    private $application;

    protected function setUp(): void
    {
        StubTransport::$events = [];
        $client = new Client(new Options(), new StubTransport());
        $hub = new Hub($client);

        $consoleListener = new ConsoleListener($hub);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ConsoleEvents::COMMAND, [$consoleListener, 'handleConsoleCommandEvent']);
        $dispatcher->addListener(ConsoleEvents::TERMINATE, [$consoleListener, 'handleConsoleTerminateEvent']);
        $dispatcher->addListener(ConsoleEvents::ERROR, [$consoleListener, 'handleConsoleErrorEvent']);

        $this->application = new Application();
        $this->application->setDispatcher($dispatcher);

        // Breadcrumb handler required to collect breadcrumbs
        $breadcrumbHandler = new BreadcrumbHandler($hub);

        // Monolog handler will turn logs above warn into sentry events.
        $handler = new Handler($hub, Logger::WARNING);
        $bufferHandler = new BufferHandler($handler);
        $dispatcher->addSubscriber(new BufferFlusher([$bufferHandler]));

        $this->hub = $hub;
        $this->logger = new Logger('test', [$bufferHandler, $breadcrumbHandler]);
    }

    /**
     * Tests that breadcrumbs are properly captured within a console command and not lost
     * on command termination.
     *
     * @return void
     */
    public function testBreadcrumbWithConsoleListener()
    {
        $command = new SentryBreadcrumbTestCommand($this->logger);
        $this->application->add($command);

        try {
            // We need to run this by the application directly because the CommandTester doesn't produce proper events.
            $this->application->doRun(new ArgvInput(['bin/console', 'sentry:breadcrumb:test']), new NullOutput());
            $this->fail();
        } catch (\Throwable $e) {
            $this->assertEquals('Breadcrumb error', $e->getMessage());
        }

        $this->assertCount(2, StubTransport::$events);
        // This is the error log line produced by the command
        $event = StubTransport::$events[0];
        $this->assertCount(1, $event->getBreadcrumbs());
        $this->assertEquals('Breadcrumb error log line', $event->getMessage());
        $this->assertEmpty($event->getExceptions());

        // This is the exception thrown by the command
        $event = StubTransport::$events[1];
        $this->assertCount(1, $event->getBreadcrumbs());
        $this->assertEquals('Breadcrumb error log line', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertCount(1, $event->getExceptions());
    }

    /**
     * Tests that the scope is reset after the command finished without any errors.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testSubCommandBreadcrumbs()
    {
        $subcommand = new SentryDummyTestCommand($this->logger);
        $this->application->add($subcommand);

        $command = new SentrySubcommandTestCommand($this->logger, $subcommand);
        $this->application->add($command);

        // We need to run this by the application directly because the CommandTester doesn't produce proper events.
        $this->application->doRun(new ArgvInput(['bin/console', 'sentry:subcommand:test']), new NullOutput());

        $this->assertCount(3, StubTransport::$events);
        // This is the log line before the subcommand is executed.
        $event = StubTransport::$events[0];
        $this->assertEquals('Subcommand will run now', $event->getMessage());
        $this->assertCount(1, $event->getBreadcrumbs());
        $this->assertEquals('Subcommand will run now', $event->getBreadcrumbs()[0]->getMessage());

        // This is the log line from the dummy command
        $event = StubTransport::$events[1];
        $this->assertEquals('This is a dummy message', $event->getMessage());
        $this->assertCount(2, $event->getBreadcrumbs());
        $this->assertEquals('Subcommand will run now', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('This is a dummy message', $event->getBreadcrumbs()[1]->getMessage());

        // This is the log line after the subcommand. Since the scope is popped on command termination,
        // it will only have 2 breadcrumbs
        $event = StubTransport::$events[2];
        $this->assertEquals('Breadcrumb after subcommand', $event->getMessage());
        $this->assertCount(2, $event->getBreadcrumbs());
        $this->assertEquals('Subcommand will run now', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('Breadcrumb after subcommand', $event->getBreadcrumbs()[1]->getMessage());
    }

    /**
     * Tests that the command that caused the crash is reported as `console.command` tag.
     *
     * @return void
     */
    public function testCrashingSubcommand()
    {
        $subcommand = new SentryBreadcrumbTestCommand($this->logger);
        $this->application->add($subcommand);

        $command = new SentrySubcommandTestCommand($this->logger, $subcommand);
        $this->application->add($command);

        try {
            $this->application->doRun(new ArgvInput(['bin/console', 'sentry:subcommand:test']), new NullOutput());
            $this->fail();
        } catch (\Throwable $e) {
            $this->assertEquals('Breadcrumb error', $e->getMessage());
        }

        $this->assertCount(4, StubTransport::$events);

        // The first log line in the root command
        $event = StubTransport::$events[0];
        $this->assertEquals('Subcommand will run now', $event->getMessage());
        $this->assertCount(1, $event->getBreadcrumbs());
        $this->assertEquals('Subcommand will run now', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('sentry:subcommand:test', $event->getTags()['console.command']);

        // This is the log line in the subcommand
        $event = StubTransport::$events[1];
        $this->assertEquals('Breadcrumb error log line', $event->getMessage());
        $this->assertCount(2, $event->getBreadcrumbs());
        $this->assertEquals('Subcommand will run now', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('Breadcrumb error log line', $event->getBreadcrumbs()[1]->getMessage());
        $this->assertEquals('sentry:breadcrumb:test', $event->getTags()['console.command']);

        // This is the exception thrown in the subcommand
        $event = StubTransport::$events[2];
        $this->assertCount(1, $event->getExceptions());
        $this->assertCount(2, $event->getBreadcrumbs());
        $this->assertEquals('Subcommand will run now', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('Breadcrumb error log line', $event->getBreadcrumbs()[1]->getMessage());
        $this->assertEquals('sentry:breadcrumb:test', $event->getTags()['console.command']);

        // This is the same exception but this time reported through the root command error handler
        // It has only the breadcrumb from the root command
        $event = StubTransport::$events[3];
        $this->assertCount(1, $event->getExceptions());
        $this->assertCount(1, $event->getBreadcrumbs());
        $this->assertEquals('Subcommand will run now', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('sentry:subcommand:test', $event->getTags()['console.command']);
    }

    /**
     * Tests that after a command was executed and finished, no information from that command
     * leaks into other commands that run afterwards.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testRunSecondCommandAfterCrashingCommand()
    {
        $subcommand = new SentryBreadcrumbTestCommand($this->logger);
        $this->application->add($subcommand);

        $command = new SentrySubcommandTestCommand($this->logger, $subcommand);
        $this->application->add($command);

        try {
            // Run first command which crashes but is handled
            $this->application->doRun(new ArgvInput(['bin/console', 'sentry:subcommand:test']), new NullOutput());
            $this->fail();
        } catch (\Throwable $e) {
            $this->assertEquals('Breadcrumb error', $e->getMessage());
        }

        // We just assert the count here to be sure, the rest is tested in testCrashingSubcommand(..)
        $this->assertCount(4, StubTransport::$events);

        $command = new SentryDummyTestCommand($this->logger);
        $this->application->add($command);

        // Run the second command which crashes unhandled.
        $this->application->doRun(new ArgvInput(['bin/console', 'sentry:dummy:test']), new NullOutput());

        // Assert 5 events because one new event was added
        $this->assertCount(5, StubTransport::$events);

        // No old breadcrumbs are attached to the new event
        $events = StubTransport::$events[4];
        $this->assertCount(1, $events->getBreadcrumbs());
        $this->assertEquals('This is a dummy message', $events->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('sentry:dummy:test', $events->getTags()['console.command']);
    }

    /**
     * Tests that even if no errors occur, breadcrumb information is available.
     *
     * @return void
     *
     * @throws \Throwable
     */
    public function testBreadcrumbsAreAvailableAfterCommandTermination()
    {
        $command = new SentryDummyTestCommand($this->logger);
        $this->application->add($command);

        $this->application->doRun(new ArgvInput(['bin/console', 'sentry:dummy:test']), new NullOutput());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];
        $this->assertCount(1, $event->getBreadcrumbs());
        $this->assertEquals('This is a dummy message', $event->getMessage());
        $this->assertEquals('sentry:dummy:test', $event->getTags()['console.command']);
    }
}
