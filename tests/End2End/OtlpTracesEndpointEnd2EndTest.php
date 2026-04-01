<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;
use Sentry\State\HubInterface;

use function Sentry\getOtlpTracesEndpointUrl;

/**
 * @runTestsInSeparateProcesses
 */
final class OtlpTracesEndpointEnd2EndTest extends TestCase
{
    public function testOTLPIntegration(): void
    {
        $kernel = new KernelWithExtraConfig([
            __DIR__ . '/Fixtures/config_otlp_default.yaml',
        ]);
        $kernel->boot();

        /** @var HubInterface $hub */
        $hub = $kernel->getContainer()->get('test.hub');
        $this->assertNotNull($hub->getClient());
        $this->assertNotNull($hub->getIntegration(\Sentry\Integration\OTLPIntegration::class));

        $this->assertSame(
            'http://example.com/sentry/api/1/integration/otlp/v1/traces/',
            getOtlpTracesEndpointUrl()
        );

        $kernel->shutdown();
    }
}
