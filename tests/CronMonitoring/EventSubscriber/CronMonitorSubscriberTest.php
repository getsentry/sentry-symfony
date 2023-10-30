<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\CronMonitoring\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Sentry\CheckInStatus;
use Sentry\SentryBundle\CronMonitoring\CronMonitor;
use Sentry\SentryBundle\CronMonitoring\CronMonitorFactory;
use Sentry\SentryBundle\CronMonitoring\EventSubscriber\CronMonitorSubscriber;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CronMonitorSubscriberTest extends TestCase
{
    /**
     * @dataProvider onConsoleCommandStartProvider
     */
    public function testOnConsoleCommandStart(string $slug, string $schedule, ?int $checkMargin, ?int $maxTime)
    {
        // Arrange
        $cronMonitor = $this->createMock(CronMonitor::class);
        $cronMonitor->expects($this->once())->method('start');

        $cronMonitorFactory = $this->createMock(CronMonitorFactory::class);
        $cronMonitorFactory
            ->expects($this->once())
            ->method('create')
            ->willReturnCallback(function ($slugParam, $scheduleParam, $checkMarginParam, $maxTimeParam) use ($cronMonitor, $slug, $schedule, $checkMargin, $maxTime, ) {
                // unfortunately cannot use ->with() because it does == instead of === check
                // this allowed bug in CronMonitorSubscriber where empty string cron-monitor-check-margin would be passed as 0 instead of null
                if ($slugParam === $slug && $scheduleParam === $schedule && $checkMarginParam === $checkMargin && $maxTime === $maxTimeParam) {
                    return $cronMonitor;
                }
                $this->fail('Invalid arguments passed to CronMonitorFactory::create');
            });

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new CronMonitorSubscriber($cronMonitorFactory));

        // Act
        $input = new ArrayInput([
            '--cron-monitor-slug' => $slug,
            '--cron-monitor-schedule' => $schedule,
        ], $this->getCronMonitorInputDefinition());
        if ($checkMargin) {
            $input->setOption('cron-monitor-check-margin', $checkMargin);
        }
        if ($maxTime) {
            $input->setOption('cron-monitor-max-time', $maxTime);
        }
        $command = new Command('test:command');
        $event = new ConsoleCommandEvent($command, $input, new NullOutput());

        $eventDispatcher->dispatch($event, ConsoleEvents::COMMAND);

        // Assert
        // Not easily possible with phpunit :( Main assert is in Arrange section
    }

    public function onConsoleCommandStartProvider(): array
    {
        return [
            ['slug', '* * * * *', 1, 1, CheckInStatus::ok()],
            ['slug2', '* * * * *', null, 2, CheckInStatus::ok()],
            ['example_slug', '2 * * * *', 3, null, CheckInStatus::ok()],
            ['example_slug2', '2/5 * * * *', null, null, CheckInStatus::ok()],
            ['slug', '* * * * *', 1, 1, CheckInStatus::error()],
        ];
    }

    public function testOnConsoleCommandStartDisabled()
    {
        // Arrange
        $cronMonitorFactory = $this->createMock(CronMonitorFactory::class);

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new CronMonitorSubscriber($cronMonitorFactory));

        // Act
        $inputDefinition = new InputDefinition();
        $input = new ArrayInput([], $inputDefinition);
        $event = new ConsoleCommandEvent(null, $input, new NullOutput());

        $eventDispatcher->dispatch($event, ConsoleEvents::COMMAND);

        // Assert
        // Not easily possible with phpunit :( Main assert is in Arrange section
    }

    public function testOnConsoleCommandTerminate()
    {
        // Arrange
        $cronMonitor = $this->createMock(CronMonitor::class);
        $cronMonitor->expects($this->once())->method('start');
        $cronMonitor->expects($this->once())->method('finishSuccess');

        $cronMonitorFactory = $this->createMock(CronMonitorFactory::class);
        $cronMonitorFactory
            ->expects($this->once())
            ->method('create')
            ->willReturn($cronMonitor);

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new CronMonitorSubscriber($cronMonitorFactory));

        // Act
        $input = new ArrayInput([
            '--cron-monitor-slug' => 'test_slug',
            '--cron-monitor-schedule' => 'test_schedule',
        ], $this->getCronMonitorInputDefinition());
        $commandEvent = new ConsoleCommandEvent(null, $input, new NullOutput());
        $command = new Command('test:command');
        $terminateEvent = new ConsoleTerminateEvent($command, $input, new NullOutput(), 0);

        $eventDispatcher->dispatch($commandEvent, ConsoleEvents::COMMAND);
        $eventDispatcher->dispatch($terminateEvent, ConsoleEvents::TERMINATE);

        // Assert
        // Not easily possible with phpunit :( Main assert is in Arrange section
    }

    public function testOnConsoleCommandTerminateNon0Status()
    {
        // Arrange
        $cronMonitor = $this->createMock(CronMonitor::class);
        $cronMonitor->expects($this->once())->method('start');
        $cronMonitor->expects($this->once())->method('finishError');

        $cronMonitorFactory = $this->createMock(CronMonitorFactory::class);
        $cronMonitorFactory
            ->expects($this->once())
            ->method('create')
            ->willReturn($cronMonitor);

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new CronMonitorSubscriber($cronMonitorFactory));

        // Act
        $input = new ArrayInput([
            '--cron-monitor-slug' => 'test_slug',
            '--cron-monitor-schedule' => 'test_schedule',
        ], $this->getCronMonitorInputDefinition());
        $commandEvent = new ConsoleCommandEvent(null, $input, new NullOutput());
        $command = new Command('test:command');
        $terminateEvent = new ConsoleTerminateEvent($command, $input, new NullOutput(), 1);

        $eventDispatcher->dispatch($commandEvent, ConsoleEvents::COMMAND);
        $eventDispatcher->dispatch($terminateEvent, ConsoleEvents::TERMINATE);

        // Assert
        // Not easily possible with phpunit :( Main assert is in Arrange section
    }

    private function getCronMonitorInputDefinition(): InputDefinition
    {
        $inputDefinition = new InputDefinition();
        $optionsArguments = [
            ['--cron-monitor-slug', '-cm', InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor slug'],
            ['--cron-monitor-schedule', '-cms', InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor schedule'],
            ['--cron-monitor-max-time', '-cmt', InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor max execution time'],
            ['--cron-monitor-check-margin', '-cmcm', InputOption::VALUE_REQUIRED, 'if command should be monitored then pass cron monitor check margin'],
        ];
        foreach ($optionsArguments as $optionArguments) {
            $inputDefinition->addOption(new InputOption(...$optionArguments));
        }

        return $inputDefinition;
    }
}
