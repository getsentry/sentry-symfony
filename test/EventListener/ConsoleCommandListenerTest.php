<?php

namespace Sentry\SentryBundle\Test\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\EventListener\ConsoleCommandListener;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
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
     * @dataProvider handleConsoleErrorEventDataProvider
     *
     * @param array<string, string> $expectedTags
     */
    public function testHandleConsoleErrorEvent(ConsoleErrorEvent $consoleEvent, array $expectedTags): void
    {
        $scope = new Scope();

        $this->hub->expects($this->once())
            ->method('withScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->hub->expects($this->once())
            ->method('captureException')
            ->with($consoleEvent->getError())
            ->willReturn(null);

        $this->listener->handleConsoleErrorEvent($consoleEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertSame($expectedTags, $event->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleConsoleErrorEventDataProvider(): \Generator
    {
        yield [
            new ConsoleErrorEvent($this->createMock(InputInterface::class), $this->createMock(OutputInterface::class), new \Exception()),
            [
                'console.command.exit_code' => '1',
            ],
        ];

        yield [
            new ConsoleErrorEvent($this->createMock(InputInterface::class), $this->createMock(OutputInterface::class), new \Exception(), new Command()),
            [
                'console.command.exit_code' => '1',
            ],
        ];

        yield [
            new ConsoleErrorEvent($this->createMock(InputInterface::class), $this->createMock(OutputInterface::class), new \Exception(), new Command('foo:bar')),
            [
                'console.command' => 'foo:bar',
                'console.command.exit_code' => '1',
            ],
        ];
    }
}
