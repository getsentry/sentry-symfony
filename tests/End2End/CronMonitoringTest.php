<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\CronMonitoring\CronMonitor;
use Sentry\SentryBundle\CronMonitoring\CronMonitorFactory;
use Sentry\SentryBundle\Tests\End2End\App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

class CronMonitoringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * @dataProvider cronMonitorSuccessDataProvider
     */
    public function testCronMonitorSuccess(string $slug, string $schedule, ?int $checkMarginMinutes, ?int $maxRuntimeMinutes, string $command)
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);

        $cronMonitor = $this->createMock(CronMonitor::class);
        $cronMonitor->expects($this->once())->method('start');
        if ('success-command' === $command) {
            $cronMonitor->expects($this->once())->method('finishSuccess');
            $cronMonitor->expects($this->never())->method('finishError');
        } else {
            $cronMonitor->expects($this->never())->method('finishSuccess');
            $cronMonitor->expects($this->once())->method('finishError');
        }

        $cronMonitorFactory = $this->createMock(CronMonitorFactory::class);
        $cronMonitorFactory
            ->expects($this->once())
            ->method('create')
            ->with($slug, $schedule, $checkMarginMinutes, $maxRuntimeMinutes)
            ->willReturn($cronMonitor);

        $kernel->boot();
        $kernel->getContainer()->set('test.' . CronMonitorFactory::class, $cronMonitorFactory);

        $arguments = ['bin/console', $command, '--cron-monitor-slug', $slug, '--cron-monitor-schedule', $schedule];
        if ($checkMarginMinutes) {
            $arguments[] = '--cron-monitor-check-margin';
            $arguments[] = (string) $checkMarginMinutes;
        }
        if ($maxRuntimeMinutes) {
            $arguments[] = '--cron-monitor-max-time';
            $arguments[] = (string) $maxRuntimeMinutes;
        }
        $input = new ArgvInput($arguments);

        $exitCode = $application->run($input, new NullOutput());

        $this->assertEquals('success-command' === $command ? 0 : 1, $exitCode);
    }

    public function cronMonitorSuccessDataProvider(): array
    {
        return [
            ['slug', '* * * * *', 1, 1, 'success-command'],
            ['slug2', '* * * * *', null, 2, 'success-command'],
            ['example_slug', '2 * * * *', 3, null, 'success-command'],
            ['example_slug2', '2/5 * * * *', null, null, 'success-command'],
            ['slug', '* * * * *', 1, 1, 'success-command'],
            ['slug', '* * * * *', 1, 1, 'main-command'],
        ];
    }
}
