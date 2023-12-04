<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Sentry\SentryBundle\Tracing\HttpClient\AbstractTraceableHttpClient;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\ScopingHttpClient;

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

    public function testScopedClients(): void
    {
        $container = $this->createContainerBuilder(true, true);

        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', '');
        $container->setParameter('kernel.container_class', '');
        $container->setParameter('kernel.build_dir', '');
        $container->setParameter('kernel.charset', '');
        $container->setParameter('kernel.cache_dir', '');
        $container->setParameter('kernel.logs_dir', '');
        $container->setParameter('kernel.runtime_environment', 'dev');

        $frameworkExtension = new FrameworkExtension();
        $frameworkExtension->load(
            [
                'framework' => [
                    'http_client' => [
                        'scoped_clients' => [
                            'scoped.http.client' => [
                                'base_uri' => 'https://example.com',
                            ],
                        ],
                    ],
                ],
            ],
            $container
        );

        $container->getDefinition('scoped.http.client')->setPublic(true);
        $container->compile();

        $service = $container->get('scoped.http.client');
        $this->assertInstanceOf(AbstractTraceableHttpClient::class, $service);

        $reflection = new \ReflectionProperty(AbstractTraceableHttpClient::class, 'client');
        $reflection->setAccessible(true);
        $parentClient = $reflection->getValue($service);
        $this->assertInstanceOf(ScopingHttpClient::class, $parentClient);

        $reflection = new \ReflectionProperty(\get_class($parentClient), 'client');
        $reflection->setAccessible(true);
        $thirdClient = $reflection->getValue($parentClient);
        // Failing check
        $this->assertNotInstanceOf(AbstractTraceableHttpClient::class, $thirdClient);

        // $this->assertInstanceOf(CurlHttpClient::class, $thirdClient);
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

        $container->register(HubInterface::class, Hub::class)
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
