<?php

declare(strict_types=1);

namespace Command;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sentry\Client;
use Sentry\Event;
use Sentry\Monolog\BreadcrumbHandler;
use Sentry\Options;
use Sentry\SentryBundle\Command\SentryBreadcrumbTestCommand;
use Sentry\SentryBundle\Command\SentryDummyTestCommand;
use Sentry\SentryBundle\Command\SentrySubcommandTestCommand;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\SentryBundle\Tests\End2End\StubTransport;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
        parent::setUp();
        $client = new Client(new Options(), new StubTransport());
        $hub = new Hub($client);

        $consoleListener = new ConsoleListener($hub);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ConsoleEvents::COMMAND, [$consoleListener, 'handleConsoleCommandEvent']);
        $dispatcher->addListener(ConsoleEvents::TERMINATE, [$consoleListener, 'handleConsoleTerminateEvent']);
        $dispatcher->addListener(ConsoleEvents::ERROR, [$consoleListener, 'handleConsoleErrorEvent']);

        $this->application = new Application();
        $this->application->setDispatcher($dispatcher);

        $breadcrumbHandler = new BreadcrumbHandler($hub);
        $this->hub = $hub;
        $this->logger = new Logger('test', [$breadcrumbHandler]);
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

        $event = Event::createEvent();
        $modifiedEvent = null;

        $this->hub->configureScope(function (Scope $scope) use ($event, &$modifiedEvent) {
            $modifiedEvent = $scope->applyToEvent($event);
        });

        $this->assertNotNull($modifiedEvent);
        $this->assertCount(1, $modifiedEvent->getBreadcrumbs());
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

        $event = Event::createEvent();
        $modifiedEvent = null;

        $this->hub->configureScope(function (Scope $scope) use ($event, &$modifiedEvent) {
            $modifiedEvent = $scope->applyToEvent($event);
        });

        $this->assertNotNull($modifiedEvent);
        // We have breadcrumbs but only from the root console command.
        $this->assertCount(2, $modifiedEvent->getBreadcrumbs());
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

        $event = Event::createEvent();
        $modifiedEvent = null;

        $this->hub->configureScope(function (Scope $scope) use ($event, &$modifiedEvent) {
            $modifiedEvent = $scope->applyToEvent($event);
        });

        $this->assertNotNull($modifiedEvent);
        $this->assertCount(2, $modifiedEvent->getBreadcrumbs());
        $this->assertEquals('sentry:breadcrumb:test', $modifiedEvent->getTags()['console.command']);
    }

    /**
     * Tests that we have the correct `console.command` tag if one command throws an unhandled exception
     * even if we had commands that threw exceptions but were handled.
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

        $command = new SentryDummyTestCommand($this->logger);
        $this->application->add($command);

        // Run the second command which crashes unhandled.
        $this->application->doRun(new ArgvInput(['bin/console', 'sentry:dummy:test']), new NullOutput());

        $event = Event::createEvent();
        $modifiedEvent = null;
        $this->hub->configureScope(function (Scope $scope) use ($event, &$modifiedEvent) {
            $modifiedEvent = $scope->applyToEvent($event);
        });

        $this->assertNotNull($modifiedEvent);
        // Breadcrumbs contain all log entries until the sentry:dummy:test command crash.
        $this->assertCount(3, $modifiedEvent->getBreadcrumbs());
        // console.command tag is properly set to the last command
        $this->assertEquals('sentry:dummy:test', $modifiedEvent->getTags()['console.command']);
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

        $event = Event::createEvent();
        $modifiedEvent = null;
        $this->hub->configureScope(function (Scope $scope) use ($event, &$modifiedEvent) {
            $modifiedEvent = $scope->applyToEvent($event);
        });

        $this->assertNotNull($modifiedEvent);
        $this->assertCount(1, $modifiedEvent->getBreadcrumbs());
        $this->assertEquals('This is a dummy message', $modifiedEvent->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('sentry:dummy:test', $modifiedEvent->getTags()['console.command']);
    }
}
