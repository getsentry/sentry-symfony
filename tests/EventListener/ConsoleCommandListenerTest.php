<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\EventListener\ConsoleCommandListener;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleCommandListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var ConsoleCommandListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new ConsoleCommandListener($this->hub);
    }

    /**
     * @dataProvider handleConsoleCommmandEventDataProvider
     *
     * @param array<string, string> $expectedTags
     */
    public function testHandleConsoleCommandEvent(ConsoleCommandEvent $consoleEvent, array $expectedTags): void
    {
        $scope = new Scope();

        $this->hub->expects($this->once())
            ->method('pushScope')
            ->willReturn($scope);

        $this->listener->handleConsoleCommandEvent($consoleEvent);

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
        $this->hub->expects($this->once())
            ->method('popScope');

        $this->listener->handleConsoleTerminateEvent(new ConsoleTerminateEvent(new Command(), $this->createMock(InputInterface::class), $this->createMock(OutputInterface::class), 0));
    }

    public function testHandleConsoleErrorEvent(): void
    {
        $scope = new Scope();
        $consoleEvent = new ConsoleErrorEvent($this->createMock(InputInterface::class), $this->createMock(OutputInterface::class), new \Exception());

        $this->hub->expects($this->once())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->hub->expects($this->once())
            ->method('captureException')
            ->with($consoleEvent->getError());

        $this->listener->handleConsoleErrorEvent($consoleEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertSame(['console.command.exit_code' => '1'], $event->getTags());
    }
}
