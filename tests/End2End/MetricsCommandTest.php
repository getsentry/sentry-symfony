<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\KernelWithMetrics;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @runTestsInSeparateProcesses
 */
class MetricsCommandTest extends WebTestCase
{
    /**
     * @var Application
     */
    private $application;

    protected static function getKernelClass(): string
    {
        return KernelWithMetrics::class;
    }

    protected function setUp(): void
    {
        StubTransport::$events = [];
        $this->application = new Application(self::bootKernel());
    }

    public function testMetricsInCommand(): void
    {
        $this->application->doRun(new ArgvInput(['bin/console', 'metrics:test']), new NullOutput());

        $this->assertCount(1, StubTransport::$events);
        $metrics = StubTransport::$events[0]->getMetrics();

        $this->assertCount(3, $metrics);

        $count = $metrics[0];
        $this->assertSame('test-counter', $count->getName());
        $this->assertSame(10.0, $count->getValue());

        $gauge = $metrics[1];
        $this->assertSame('test-gauge', $gauge->getName());
        $this->assertSame(20.51, $gauge->getValue());

        $distribution = $metrics[2];
        $this->assertSame('test-distribution', $distribution->getName());
        $this->assertSame(100.81, $distribution->getValue());
    }
}
