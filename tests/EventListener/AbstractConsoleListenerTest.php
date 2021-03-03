<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractConsoleListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
    }

    /**
     * @dataProvider handleConsoleCommmandEventDataProvider
     *
     * @param array<string, string> $expectedTags
     */
    public function testHandleConsoleCommandEvent(ConsoleCommandEvent $consoleEvent, array $expectedTags): void
    {
        $listenerClass = static::getListenerClass();
        $scope = new Scope();
        $listener = new $listenerClass($this->hub);

        $this->hub->expects($this->once())
            ->method('pushScope')
            ->willReturn($scope);

        $listener->handleConsoleCommandEvent($consoleEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertSame($expectedTags, $event->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleConsoleCommmandEventDataProvider(): \Generator
    {
        yield [
            new ConsoleCommandEvent(null, $this->createMock(InputInterface::class), $this->createMock(OutputInterface::class)),
            [],
        ];

        yield [
            new ConsoleCommandEvent(new Command(), $this->createMock(InputInterface::class), $this->createMock(OutputInterface::class)),
            [],
        ];

        yield [
            new ConsoleCommandEvent(new Command('foo:bar'), $this->createMock(InputInterface::class), $this->createMock(OutputInterface::class)),
            ['console.command' => 'foo:bar'],
        ];
    }

    public function testHandleConsoleTerminateEvent(): void
    {
        $listenerClass = static::getListenerClass();
        $listener = new $listenerClass($this->hub);

        $this->hub->expects($this->once())
            ->method('popScope');

        $listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(new Command(), $this->createMock(InputInterface::class), $this->createMock(OutputInterface::class), 0));
    }

    /**
     * @dataProvider handleConsoleErrorEventDataProvider
     */
    public function testHandleConsoleErrorEvent(bool $captureErrors): void
    {
        $scope = new Scope();
        $consoleEvent = new ConsoleErrorEvent($this->createMock(InputInterface::class), $this->createMock(OutputInterface::class), new \Exception());
        $listenerClass = static::getListenerClass();
        $listener = new $listenerClass($this->hub, $captureErrors);

        $this->hub->expects($this->once())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->hub->expects($captureErrors ? $this->once() : $this->never())
            ->method('captureException')
            ->with($consoleEvent->getError());

        $listener->handleConsoleErrorEvent($consoleEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertSame(['console.command.exit_code' => '1'], $event->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleConsoleErrorEventDataProvider(): \Generator
    {
        yield [true];
        yield [false];
    }

    /**
     * @return class-string<ConsoleListener>
     */
    abstract protected static function getListenerClass(): string;
}
