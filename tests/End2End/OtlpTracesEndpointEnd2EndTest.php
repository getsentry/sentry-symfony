<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\SentryManager;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;

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

        /** @var SentryManager $sentry */
        $sentry = $kernel->getContainer()->get('test.sentry');
        $this->assertNotNull($sentry->getClient()->getIntegration(\Sentry\Integration\OTLPIntegration::class));

        $this->assertSame(
            'http://example.com/sentry/api/1/integration/otlp/v1/traces/',
            getOtlpTracesEndpointUrl()
        );

        $kernel->shutdown();
    }
}
