<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpClientTracingPassTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!self::isHttpClientPackageInstalled()) {
            self::markTestSkipped('This test requires the "symfony/http-client" Composer package to be installed.');
        }
    }

    public function testProcess(): void
    {
        $container = $this->createContainerBuilder(true, true);
        $container->compile();

        $this->assertSame(TraceableHttpClient::class, $container->findDefinition('http.client')->getClass());
    }

    /**
     * @dataProvider processDoesNothingIfConditionsForEnablingTracingAreMissingDataProvider
     */
    public function testProcessDoesNothingIfConditionsForEnablingTracingAreMissing(bool $isTracingEnabled, bool $isHttpClientTracingEnabled): void
    {
        $container = $this->createContainerBuilder($isTracingEnabled, $isHttpClientTracingEnabled);
        $container->compile();

        $this->assertSame(HttpClient::class, $container->getDefinition('http.client')->getClass());
    }

    /**
     * @return \Generator<mixed>
     */
    public function processDoesNothingIfConditionsForEnablingTracingAreMissingDataProvider(): \Generator
    {
        yield [
            true,
            false,
        ];

        yield [
            false,
            false,
        ];

        yield [
            false,
            true,
        ];
    }

    private function createContainerBuilder(bool $isTracingEnabled, bool $isHttpClientTracingEnabled): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new HttpClientTracingPass());
        $container->setParameter('sentry.tracing.enabled', $isTracingEnabled);
        $container->setParameter('sentry.tracing.http_client.enabled', $isHttpClientTracingEnabled);

        $container->register(HubInterface::class, HubInterface::class)
            ->setPublic(true);

        $container->register('http.client', HttpClient::class)
            ->setPublic(true)
            ->addTag('http_client.client');

        return $container;
    }

    private static function isHttpClientPackageInstalled(): bool
    {
        return interface_exists(HttpClientInterface::class);
    }
}
