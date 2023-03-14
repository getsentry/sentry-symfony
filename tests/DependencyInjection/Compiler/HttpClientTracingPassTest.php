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
use Symfony\Component\HttpClient\ScopingHttpClient;
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
        $thirdClient = $reflection->getValue($service);
        // Failing check
        $this->assertNotInstanceOf(AbstractTraceableHttpClient::class, $thirdClient);
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

        $container->register(HubInterface::class, Hub::class)
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
