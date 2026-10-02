<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\CheckInStatus;
use Sentry\EventType;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithScheduler;
use Sentry\SentryBundle\Tests\End2End\App\Messenger\StaticInMemoryTransport;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Scheduler\Event\PreRunEvent;

/**
 * @runTestsInSeparateProcesses
 */
final class SchedulerEnd2EndTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return KernelWithScheduler::class;
    }

    protected function setUp(): void
    {
        if (!class_exists(PreRunEvent::class)) {
            $this->markTestSkipped('This test requires the "symfony/scheduler" Composer package (6.4 or newer) to be installed.');
        }

        StubTransport::$events = [];
        StaticInMemoryTransport::reset();
    }

    public function testScheduledMessagesSendCheckIns(): void
    {
        $this->consume('scheduler_default', 2);

        $checkIns = $this->getCheckIns();

        $this->assertSame([
            ['scheduled-message', CheckInStatus::inProgress()],
            ['scheduled-message', CheckInStatus::ok()],
            ['failing-scheduled-message', CheckInStatus::inProgress()],
            ['failing-scheduled-message', CheckInStatus::error()],
        ], array_map(static function (array $checkIn): array {
            return [$checkIn['slug'], $checkIn['status']];
        }, $checkIns));

        $this->assertSame($checkIns[0]['id'], $checkIns[1]['id']);
        $this->assertSame($checkIns[2]['id'], $checkIns[3]['id']);
        $this->assertSame(['type' => 'crontab', 'value' => '* * * * *', 'unit' => ''], $checkIns[0]['schedule']);
    }

    public function testRedispatchedMessageIsMonitoredWhenConsumed(): void
    {
        $this->consume('scheduler_redispatch', 1);

        $this->assertSame([], $this->getCheckIns());

        $this->consume('async', 1);

        $this->assertSame([
            ['redispatch-scheduled-message', CheckInStatus::inProgress()],
            ['redispatch-scheduled-message', CheckInStatus::ok()],
        ], array_map(static function (array $checkIn): array {
            return [$checkIn['slug'], $checkIn['status']];
        }, $this->getCheckIns()));
    }

    private function consume(string $receiver, int $limit): void
    {
        $application = new Application(static::bootKernel());

        $commandTester = new CommandTester($application->find('messenger:consume'));
        $commandTester->execute([
            'receivers' => [$receiver],
            '--limit' => $limit,
            '--time-limit' => 5,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * @return list<array{id: string, slug: string, status: CheckInStatus, schedule: array<string, mixed>|null}>
     */
    private function getCheckIns(): array
    {
        $checkIns = [];

        foreach (StubTransport::$events as $event) {
            if (EventType::checkIn() !== $event->getType()) {
                continue;
            }

            $checkIn = $event->getCheckIn();
            $this->assertNotNull($checkIn);
            $monitorConfig = $checkIn->getMonitorConfig();

            $checkIns[] = [
                'id' => $checkIn->getId(),
                'slug' => $checkIn->getMonitorSlug(),
                'status' => $checkIn->getStatus(),
                'schedule' => null !== $monitorConfig ? $monitorConfig->getSchedule()->toArray() : null,
            ];
        }

        return $checkIns;
    }
}
