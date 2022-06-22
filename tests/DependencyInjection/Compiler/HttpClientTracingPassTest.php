<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\HubAdapter;
use Sentry\State\HubInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class HttpClientTracingPassTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!self::isHttpClientPackageInstalled()) {
            self::markTestSkipped('This test requires the "symfony/http-client" Composer package to be installed.');
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @dataProvider provideDisableContainerParameters
     */
    public function testShouldNotDecorateHttpClientServicesIfDisabled(array $params): void
    {
        $container = new ContainerBuilder(new ParameterBag($params));
        $container->register('http.client', HttpClient::class)
            ->setPublic(true)
            ->addTag('http_client.client');

        $container->addCompilerPass(new HttpClientTracingPass());
        $container->compile();

        $this->assertEquals(HttpClient::class, $container->getDefinition('http.client')->getClass());
    }

    /**
     * @return iterable<mixed[]>
     */
    public function provideDisableContainerParameters(): iterable
    {
        yield [['sentry.tracing.enabled' => true, 'sentry.tracing.http_client.enabled' => false]];
        yield [['sentry.tracing.enabled' => false, 'sentry.tracing.http_client.enabled' => false]];
        yield [['sentry.tracing.enabled' => false, 'sentry.tracing.http_client.enabled' => true]];
    }

    public function testShouldDecorateHttpClients(): void
    {
        $container = new ContainerBuilder(new ParameterBag(['sentry.tracing.enabled' => true, 'sentry.tracing.http_client.enabled' => true]));
        $container->register(HubInterface::class)
            ->setFactory([HubAdapter::class, 'getInstance']);
        $container->register('http.client', HttpClient::class)
            ->setPublic(true)
            ->addTag('http_client.client');

        $container->addCompilerPass(new HttpClientTracingPass());
        $container->compile();

        $this->assertEquals(TraceableHttpClient::class, $container->findDefinition('http.client')->getClass());
    }

    private static function isHttpClientPackageInstalled(): bool
    {
        return interface_exists(HttpClientInterface::class);
    }
}
