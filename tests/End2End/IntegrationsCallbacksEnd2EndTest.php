<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\Client;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;
use Sentry\SentryBundle\Tests\End2End\Fixtures\TestIntegrationForFactory;
use Sentry\SentryBundle\Tests\End2End\Fixtures\TestIntegrationForInvokable;
use Sentry\State\HubInterface;

/**
 * @runTestsInSeparateProcesses
 */
final class IntegrationsCallbacksEnd2EndTest extends TestCase
{
    public function testInvokableServiceCallback(): void
    {
        $kernel = new KernelWithExtraConfig([
            __DIR__ . '/App/config.yml',
            __DIR__ . '/Fixtures/config_invokable.yaml',
        ]);
        $kernel->boot();

        /**
         * @var $hub HubInterface
         */
        $hub = $kernel->getContainer()->get('test.hub');
        /**
         * @var $client Client
         */
        $client = $hub->getClient();

        // This integration is added by the declared service
        $this->assertNotNull($client->getIntegration(TestIntegrationForInvokable::class));

        $kernel->shutdown();
    }

    public function testFactoryServiceCallback(): void
    {
        $kernel = new KernelWithExtraConfig([
            __DIR__ . '/App/config.yml',
            __DIR__ . '/Fixtures/config_factory.yaml',
        ]);
        $kernel->boot();

        /**
         * @var $hub HubInterface
         */
        $hub = $kernel->getContainer()->get('test.hub');
        /**
         * @var $client Client
         */
        $client = $hub->getClient();

        // This integration is added by the declared service
        $this->assertNotNull($client->getIntegration(TestIntegrationForFactory::class));

        $kernel->shutdown();
    }
}
