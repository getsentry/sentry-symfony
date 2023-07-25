<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\MonitorScheduleUnit;
use Sentry\SentryBundle\Monitor\MonitorFactory;
use Sentry\SentryBundle\Monitor\MonitorInterface;

class CronJobFactoryTest extends TestCase
{
    public function testCronJob(): void
    {
        // Setup test
        $factory = new MonitorFactory('test', 'test-release');
        $monitorConfig = new MonitorConfig(
            MonitorSchedule::crontab('*/5 * * * *'),
            5,
            30,
            'UTC'
        );
        $cronJob = $factory->getMonitor('test-cronjob', $monitorConfig);
        $this->assertInstanceOf(MonitorInterface::class, $cronJob);

        // Create a CheckIn
        $checkIn = $cronJob->inProgress();
        $this->assertEquals('test', $checkIn->getEnvironment());
        $this->assertEquals('test-release', $checkIn->getRelease());
        $this->assertEquals('test-cronjob', $checkIn->getMonitorSlug());
    }

    public function testInterval(): void
    {
        // Setup test
        $factory = new MonitorFactory('test', 'test-release');
        $monitorConfig = new MonitorConfig(
            MonitorSchedule::interval(
                30,
                MonitorScheduleUnit::minute()
            ),
            5,
            30,
            'UTC'
        );
        $interval = $factory->getMonitor('test-interval', $monitorConfig);
        $this->assertInstanceOf(MonitorInterface::class, $interval);

        // Create a CheckIn
        $checkIn = $interval->inProgress();
        $this->assertEquals('test', $checkIn->getEnvironment());
        $this->assertEquals('test-release', $checkIn->getRelease());
        $this->assertEquals('test-interval', $checkIn->getMonitorSlug());
    }
}
