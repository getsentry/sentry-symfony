<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
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

    /**
     * @param string[] $argv
     *
     * @dataProvider handleConsoleCommandEventFiltersSensitiveValuesDataProvider
     */
    public function testHandleConsoleCommandEventFiltersSensitiveValues(Options $options, array $argv, string $expectedFullCommand): void
    {
        $listenerClass = static::getListenerClass();
        $scope = new Scope();
        $listener = new $listenerClass($this->hub);

        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')
            ->willReturn($options);

        $this->hub->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('pushScope')
            ->willReturn($scope);

        $command = new Command('app:import');
        $command->setDefinition(new InputDefinition([
            new InputArgument('command'),
            new InputArgument('token'),
            new InputOption('password', 'p', InputOption::VALUE_REQUIRED),
            new InputOption('limit', null, InputOption::VALUE_REQUIRED),
            new InputOption('force', 'f', InputOption::VALUE_NONE),
        ]));

        $input = new ArgvInput($argv);

        // Like the application, which ignores invalid input until the command runs
        try {
            $input->bind($command->getDefinition());
        } catch (ExceptionInterface $exception) {
        }

        $listener->handleConsoleCommandEvent(new ConsoleCommandEvent($command, $input, new NullOutput()));

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertSame(['Full command' => $expectedFullCommand], $event->getExtra());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleConsoleCommandEventFiltersSensitiveValuesDataProvider(): \Generator
    {
        yield 'The legacy options do not filter the command' => [
            new Options(['send_default_pii' => true]),
            ['bin/console', 'app:import', '--password=secret'],
            "'app:import' --password=secret",
        ];

        yield 'The value of a sensitive option given after "="' => [
            new Options(['data_collection' => []]),
            ['bin/console', 'app:import', '--password=secret', '--limit=10'],
            "'app:import' --password=[Filtered] --limit=10",
        ];

        yield 'The value of a sensitive option given as separate token' => [
            new Options(['data_collection' => []]),
            ['bin/console', 'app:import', '--password', 'secret'],
            "'app:import' --password [Filtered]",
        ];

        yield 'The value of a sensitive option given with its shortcut' => [
            new Options(['data_collection' => []]),
            ['bin/console', 'app:import', '-p', 'secret'],
            "'app:import' -p [Filtered]",
        ];

        yield 'The value of a sensitive option given right after its shortcut' => [
            new Options(['data_collection' => []]),
            ['bin/console', 'app:import', '-psecret'],
            "'app:import' -p[Filtered]",
        ];

        yield 'The value of a sensitive option that is escaped' => [
            new Options(['data_collection' => []]),
            ['bin/console', 'app:import', '--password=my secret'],
            "'app:import' --password=[Filtered]",
        ];

        yield 'The value of a sensitive argument' => [
            new Options(['data_collection' => []]),
            ['bin/console', 'app:import', 'abc123', '--limit=10'],
            "'app:import' [Filtered] --limit=10",
        ];

        yield 'The value of a sensitive option given right after combined shortcuts' => [
            new Options(['data_collection' => []]),
            ['bin/console', 'app:import', '-fpsecret'],
            "'app:import' -fp[Filtered]",
        ];

        yield 'The arguments and options of an invalid input are filtered' => [
            new Options(['data_collection' => []]),
            ['bin/console', 'app:import', '--unknown', '--password=secret'],
            "'app:import' [Filtered]",
        ];

        yield 'The legacy options do not filter an invalid input' => [
            new Options(['send_default_pii' => true]),
            ['bin/console', 'app:import', '--unknown', '--password=secret'],
            "'app:import' --unknown --password=secret",
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
