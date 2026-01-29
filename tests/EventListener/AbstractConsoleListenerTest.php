<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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
     * @param array<string, string> $expectedExtra
     */
    public function testHandleConsoleCommandEvent(ConsoleCommandEvent $consoleEvent, array $expectedTags, array $expectedExtra): void
    {
        $listenerClass = static::getListenerClass();
        $scope = new Scope();
        $listener = new $listenerClass($this->hub);

        $this->hub->expects($this->once())
            ->method('pushScope')
            ->willReturn($scope);

        $listener->handleConsoleCommandEvent($consoleEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertSame($expectedTags, $event->getTags());
        $this->assertSame($expectedExtra, $event->getExtra());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleConsoleCommmandEventDataProvider(): \Generator
    {
        yield [
            new ConsoleCommandEvent(null, new ArrayInput([]), new NullOutput()),
            [],
            [],
        ];

        yield [
            new ConsoleCommandEvent(new Command(), new ArrayInput([]), new NullOutput()),
            [],
            [],
        ];

        yield [
            new ConsoleCommandEvent(new Command('foo:bar'), new ArrayInput([]), new NullOutput()),
            ['console.command' => 'foo:bar'],
            [],
        ];

        yield [
            new ConsoleCommandEvent(new Command('foo:bar'), new ArgvInput(['bin/console', 'foo:bar', '--foo=bar']), new NullOutput()),
            ['console.command' => 'foo:bar'],
            ['Full command' => "'foo:bar' --foo=bar"],
        ];
    }

    public function testHandleConsoleTerminateEvent(): void
    {
        $listenerClass = static::getListenerClass();
        $listener = new $listenerClass($this->hub);

        $this->hub->expects($this->once())
            ->method('popScope');

        $listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(new Command(), new ArrayInput([]), new NullOutput(), 0));
    }

    /**
     * @dataProvider handleConsoleErrorEventDataProvider
     */
    public function testHandleConsoleErrorEvent(bool $captureErrors): void
    {
        $scope = new Scope();
        $consoleEvent = new ConsoleErrorEvent(new ArrayInput([]), new NullOutput(), new \Exception());
        $listenerClass = static::getListenerClass();
        $listener = new $listenerClass($this->hub, $captureErrors);

        $this->hub->expects($this->once())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->hub->expects($captureErrors ? $this->once() : $this->never())
            ->method('captureEvent')
            ->with(
                $this->anything(),
                $this->logicalAnd(
                    $this->isInstanceOf(EventHint::class),
                    $this->callback(static function (EventHint $subject) use ($consoleEvent) {
                        self::assertSame($consoleEvent->getError(), $subject->exception);
                        self::assertNotNull($subject->mechanism);
                        self::assertFalse($subject->mechanism->isHandled());

                        return true;
                    })
                )
            );

        $listener->handleConsoleErrorEvent($consoleEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
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
