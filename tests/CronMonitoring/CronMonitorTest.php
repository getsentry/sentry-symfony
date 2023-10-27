<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\CronMonitoring;

use PHPUnit\Framework\TestCase;
use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\MonitorSchedule;
use Sentry\SentryBundle\CronMonitoring\CronMonitor;
use Sentry\SentryBundle\Tests\Stubs\TestableHubInterface;

final class CronMonitorTest extends TestCase
{
    /**
     * @dataProvider monitorDataProvider
     */
    public function testSuccess(string $slug, string $schedule, ?int $checkMarginMinutes, ?int $maxRuntimeMinutes, CheckInStatus $testedStatus)
    {
        // Arrange
        $hub = $this->createMock(TestableHubInterface::class);
        $monitorSchedule = MonitorSchedule::crontab($schedule);
        $monitorConfig = new MonitorConfig(
            $monitorSchedule,
            $checkMarginMinutes,
            $maxRuntimeMinutes,
            date_default_timezone_get()
        );

        $checkInId = uniqid('checkInId');
        $inProgressCalled = $finishCalled = false;
        $hub
            ->expects($this->exactly(2))
            ->method('captureCheckIn')
            ->willReturnCallback(
                function (string $callSlug, CheckInStatus $callStatus, ?int $callDuration, MonitorConfig $callMonitorConfig, $callCheckInId) use ($slug, $monitorConfig, $testedStatus, &$checkInId, &$inProgressCalled, &$finishCalled) {
                    if ($callSlug === $slug && $callStatus === CheckInStatus::inProgress() && null === $callDuration && $callMonitorConfig === $monitorConfig) {
                        $inProgressCalled = true;

                        return $checkInId;
                    }
                    if ($callSlug === $slug && $callStatus === $testedStatus && null === $callDuration && $callMonitorConfig === $monitorConfig && $callCheckInId === $checkInId) {
                        $finishCalled = true;

                        return null;
                    }
                    $this->fail('Unexpected call to Hub::captureCheckIn');
                });

        $cronMonitor = new CronMonitor($hub, $monitorConfig, $slug);
        $cronMonitor->start();

        // Act
        if ($testedStatus === CheckInStatus::ok()) {
            $cronMonitor->finishSuccess();
        } else {
            $cronMonitor->finishError();
        }

        // Assert
        $this->assertTrue($inProgressCalled);
        $this->assertTrue($finishCalled);
    }

    public function monitorDataProvider(): array
    {
        return [
            ['slug', '* * * * *', 1, 1, CheckInStatus::ok()],
            ['slug2', '* * * * *', null, 2, CheckInStatus::ok()],
            ['example_slug', '2 * * * *', 3, null, CheckInStatus::ok()],
            ['example_slug2', '2/5 * * * *', null, null, CheckInStatus::ok()],
            ['slug', '* * * * *', 1, 1, CheckInStatus::error()],
        ];
    }
}
