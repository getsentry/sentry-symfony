<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\KernelWithMetricsDisabled;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @runTestsInSeparateProcesses
 */
class MetricsDisabledEnd2EndTest extends WebTestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    protected static function getKernelClass(): string
    {
        return KernelWithMetricsDisabled::class;
    }

    public function testMetricsAreFlushedWhenEnableMetricsIsFalse(): void
    {
        $client = static::createClient();

        $client->request('GET', '/metrics');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);
        $this->assertCount(3, StubTransport::$events[0]->getMetrics());
    }
}
