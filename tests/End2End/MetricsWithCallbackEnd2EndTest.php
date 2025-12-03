<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\KernelWithMetricsCallback;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @runTestsInSeparateProcesses
 */
class MetricsWithCallbackEnd2EndTest extends WebTestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    protected static function getKernelClass(): string
    {
        return KernelWithMetricsCallback::class;
    }

    public function testMetricsAreFlushedAfterRequest(): void
    {
        $client = static::createClient();

        $client->request('GET', '/metrics');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);

        $metrics = StubTransport::$events[0]->getMetrics();
        $this->assertCount(2, $metrics);

        // Counter metric removed by `before_send_metric`
        $gauge = $metrics[0];
        $this->assertSame('test-gauge', $gauge->getName());
        $this->assertSame(20.51, $gauge->getValue());

        $distribution = $metrics[1];
        $this->assertSame('test-distribution', $distribution->getName());
        $this->assertSame(100.81, $distribution->getValue());
    }
}
