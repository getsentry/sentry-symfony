<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\CheckInStatus;
use Sentry\SentryBundle\EventListener\CronMonitorListener;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class CronMonitorListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
    }

    public function testHandleConsoleCommandEvent(): void
    {
        $listener = new CronMonitorListener($this->hub, [Command::class => 'bar']);

        $this->hub->expects($this->once())
            ->method('captureCheckIn')
            ->with('bar', CheckInStatus::inProgress())
        ;

        $consoleEvent = new ConsoleCommandEvent(new Command('foo:bar'), new ArrayInput([]), new NullOutput());
        $listener->handleConsoleCommandEvent($consoleEvent);
    }

    public function testHandleConsoleCommandEventSkipped(): void
    {
        $listener = new CronMonitorListener($this->hub, []);

        $this->hub->expects($this->never())
            ->method('captureCheckIn');

        $consoleEvent = new ConsoleCommandEvent(new Command('foo:bar'), new ArrayInput([]), new NullOutput());
        $listener->handleConsoleCommandEvent($consoleEvent);
    }

    public function testHandleConsoleCommandEventSkippedWithEmptyCommand(): void
    {
        $listener = new CronMonitorListener($this->hub, [Command::class => 'bar']);

        $this->hub->expects($this->never())
            ->method('captureCheckIn');

        $consoleEvent = new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput());
        $listener->handleConsoleCommandEvent($consoleEvent);
    }

    /**
     * @dataProvider provideTerminateEvents
     */
    public function testHandleConsoleTerminateEvent(ConsoleTerminateEvent $consoleEvent, CheckInStatus $expectedState): void
    {
        $listener = new CronMonitorListener($this->hub, [Command::class => 'bar']);

        $this->hub->expects($this->once())
            ->method('captureCheckIn')
            ->with('bar', $expectedState)
        ;

        $listener->handleConsoleTerminateEvent($consoleEvent);
    }

    /**
     * @return \Generator<mixed>
     */
    public function provideTerminateEvents(): \Generator
    {
        yield [
            new ConsoleTerminateEvent(new Command('foo:bar'), new ArrayInput([]), new NullOutput(), Command::SUCCESS),
            CheckInStatus::ok(),
        ];

        yield [
            new ConsoleTerminateEvent(new Command('foo:bar'), new ArrayInput([]), new NullOutput(), Command::FAILURE),
            CheckInStatus::error(),
        ];
    }

    public function testHandleConsoleErrorEvent(): void
    {
        $listener = new CronMonitorListener($this->hub, [Command::class => 'bar']);

        $this->hub->expects($this->once())
            ->method('captureCheckIn')
            ->with('bar', CheckInStatus::error())
        ;

        $consoleEvent = new ConsoleErrorEvent(
            new ArrayInput([]),
            new NullOutput(),
            new \Exception(),
            new Command('foo:bar')
        );

        $listener->handleConsoleErrorEvent($consoleEvent);
    }
}
