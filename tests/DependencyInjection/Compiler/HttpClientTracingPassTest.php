<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpClientTracingPassTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!self::isHttpClientPackageInstalled()) {
            self::markTestSkipped('This test requires the "symfony/http-client" Composer package to be installed.');
        }
    }

    /**
     * @dataProvider processDataProvider
     */
    public function testProcess(string $httpClientServiceId): void
    {
        $container = $this->createContainerBuilder(true, true, $httpClientServiceId);
        $container->compile();

        $this->assertSame(TraceableHttpClient::class, $container->getDefinition($httpClientServiceId)->getClass());
    }

    public function processDataProvider(): \Generator
    {
        yield 'The framework version is >=6.3' => [
            'http_client.transport',
        ];

        yield 'The framework version is <6.3 and the mocked HTTP client is decorated by the retryable client' => [
            'http_client.retryable.inner.mock_client',
        ];

        yield 'The framework version is <6.3 and the mocked HTTP client is decorated by the profiler' => [
            '.debug.http_client.inner.mock_client',
        ];

        yield 'The framework version is <6.3 and the mocked HTTP client is not decorated' => [
            'http_client.mock_client',
        ];

        yield 'The framework version is <6.3 and the HTTP client is not mocked' => [
            'http_client',
        ];
    }

    /**
     * @dataProvider processDoesNothingIfConditionsForEnablingTracingAreMissingDataProvider
     */
    public function testProcessDoesNothingIfConditionsForEnablingTracingAreMissing(bool $isTracingEnabled, bool $isHttpClientTracingEnabled): void
    {
        $container = $this->createContainerBuilder($isTracingEnabled, $isHttpClientTracingEnabled, 'http_client.transport');
        $container->compile();

        $this->assertSame(HttpClientInterface::class, $container->getDefinition('http_client.transport')->getClass());
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

    private function createContainerBuilder(bool $isTracingEnabled, bool $isHttpClientTracingEnabled, string $httpClientServiceId): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new HttpClientTracingPass());
        $container->setParameter('sentry.tracing.enabled', $isTracingEnabled);
        $container->setParameter('sentry.tracing.http_client.enabled', $isHttpClientTracingEnabled);

        $container->register(HubInterface::class, HubInterface::class)
            ->setPublic(true);

        $container->register($httpClientServiceId, HttpClientInterface::class)
            ->setPublic(true);

        return $container;
    }

    private static function isHttpClientPackageInstalled(): bool
    {
        return interface_exists(HttpClientInterface::class);
    }
}
