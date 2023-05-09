<?php

namespace Sentry\SentryBundle\Tests\Cron;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Cron\CronJobFactory;
use Sentry\SentryBundle\Cron\CronJobInterface;

class CronJobFactoryTest extends TestCase
{
    public function testCronJob(): void
    {
        // Setup test
        $factory = new CronJobFactory('test', 'test-release');
        $cronjob = $factory->getCronjob('test-monitor');
        $this->assertInstanceOf(CronJobInterface::class, $cronjob);

        // Create a CheckIn
        $checkIn = $cronjob->inProgress();
        $this->assertEquals('test', $checkIn->getEnvironment());
        $this->assertEquals('test-release', $checkIn->getRelease());
        $this->assertEquals('test-monitor', $checkIn->getMonitorSlug());
    }
}
