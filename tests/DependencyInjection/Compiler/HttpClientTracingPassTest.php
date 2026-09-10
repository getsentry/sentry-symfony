<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\MockHttpClient;
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

    public function testProcessDoesNothingIfHttpClientServiceCannotBeFound(): void
    {
        $container = $this->createContainerBuilder(true, true, null);
        $container->compile();

        $this->assertFalse($container->hasDefinition('http_client'));
    }

    /**
     * @dataProvider frameworkClientProvider
     */
    public function testFrameworkDefaultOptionsAreAvailableForCollection(string $serviceId): void
    {
        $container = $this->createContainerBuilder(true, true, $serviceId);
        $options = ['headers' => ['X-Default' => 'visible', 'Cookie' => 'theme=dark']];
        $container->getDefinition($serviceId)
            ->setFactory([HttpClient::class, 'create'])
            ->setArguments([$options]);
        $container->compile();

        $this->assertSame($options, $container->getDefinition($serviceId)->getArgument(2));
    }

    public function testMockClientDoesNotCollectTransportDefaultOptions(): void
    {
        $container = $this->createContainerBuilder(true, true, 'http_client.transport');
        $container->getDefinition('http_client.transport')
            ->setFactory([HttpClient::class, 'create'])
            ->setArguments([['headers' => ['X-Default' => 'unused', 'Cookie' => 'theme=unused']]]);
        $container->register('http_client.mock_client', MockHttpClient::class)
            ->setDecoratedService('http_client.transport', null, -10);
        $container->compile();

        $this->assertSame([], $container->getDefinition('http_client.transport')->getArgument(2));
    }

    /**
     * @return \Generator<mixed>
     */
    public function frameworkClientProvider(): \Generator
    {
        yield 'transport service' => ['http_client.transport'];
        yield 'legacy service' => ['http_client'];
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

    private function createContainerBuilder(bool $isTracingEnabled, bool $isHttpClientTracingEnabled, ?string $httpClientServiceId): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new HttpClientTracingPass());
        $container->setParameter('sentry.tracing.enabled', $isTracingEnabled);
        $container->setParameter('sentry.tracing.http_client.enabled', $isHttpClientTracingEnabled);

        $container->register(HubInterface::class, HubInterface::class)
            ->setPublic(true);

        if (null !== $httpClientServiceId) {
            $container->register($httpClientServiceId, HttpClientInterface::class)
                ->setPublic(true);
        }

        return $container;
    }

    private static function isHttpClientPackageInstalled(): bool
    {
        return interface_exists(HttpClientInterface::class);
    }
}
