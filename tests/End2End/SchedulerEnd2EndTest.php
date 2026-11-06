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
            CheckInStatus::inProgress(),
            CheckInStatus::ok(),
            CheckInStatus::inProgress(),
            CheckInStatus::error(),
        ], array_column($checkIns, 'status'));

        $this->assertSame('default-scheduled-message', $checkIns[0]['slug']);
        $this->assertSame('default-failing-scheduled-message', $checkIns[2]['slug']);
        $this->assertSame($checkIns[0]['slug'], $checkIns[1]['slug']);
        $this->assertSame($checkIns[2]['slug'], $checkIns[3]['slug']);
        $this->assertSame($checkIns[0]['id'], $checkIns[1]['id']);
        $this->assertSame($checkIns[2]['id'], $checkIns[3]['id']);
    }

    public function testRedispatchedMessageIsMonitoredWhenConsumed(): void
    {
        $this->consume('scheduler_redispatch', 1);

        $this->assertSame([], $this->getCheckIns());

        $this->consume('async', 1);

        $checkIns = $this->getCheckIns();

        $this->assertSame([CheckInStatus::inProgress(), CheckInStatus::ok()], array_column($checkIns, 'status'));
        $this->assertSame('redispatch-scheduled-message', $checkIns[0]['slug']);
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
