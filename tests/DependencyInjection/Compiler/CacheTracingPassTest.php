<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\CacheTracingPass;
use Sentry\SentryBundle\Tracing\Cache\TraceableCacheAdapter;
use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapter;
use Sentry\State\HubInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class CacheTracingPassTest extends TestCase
{
    /**
     * @dataProvider processDataProvider
     *
     * @param array<string, Definition> $definitions
     */
    public function testProcess(array $definitions, string $expectedDefinitionClass, string $expectedInnerDefinitionClass): void
    {
        $container = $this->createContainerBuilder(true);
        $container->addDefinitions($definitions);
        $container->compile();

        $cacheTraceableDefinition = $container->findDefinition('app.cache');

        $this->assertSame($expectedDefinitionClass, $cacheTraceableDefinition->getClass());
        $this->assertInstanceOf(Definition::class, $cacheTraceableDefinition->getArgument(1));
        $this->assertSame($expectedInnerDefinitionClass, $cacheTraceableDefinition->getArgument(1)->getClass());
    }

    /**
     * @return \Generator<mixed>
     */
    public function processDataProvider(): \Generator
    {
        $cacheAdapter = $this->createMock(AdapterInterface::class);
        $tagAwareCacheAdapter = $this->createMock(TagAwareAdapterInterface::class);

        yield 'Cache pool adapter service' => [
            [
                'app.cache' => (new Definition(\get_class($cacheAdapter)))
                    ->setPublic(true)
                    ->addTag('cache.pool'),
            ],
            TraceableCacheAdapter::class,
            \get_class($cacheAdapter),
        ];

        yield 'Tag-aware cache adapter service' => [
            [
                'app.cache' => (new Definition(\get_class($tagAwareCacheAdapter)))
                    ->setPublic(true)
                    ->addTag('cache.pool'),
            ],
            TraceableTagAwareCacheAdapter::class,
            \get_class($tagAwareCacheAdapter),
        ];

        yield 'Cache pool adapter service inheriting parent service' => [
            [
                'app.cache.parent' => new Definition(\get_class($cacheAdapter)),
                'app.cache' => (new ChildDefinition('app.cache.parent'))
                    ->setPublic(true)
                    ->addTag('cache.pool'),
            ],
            TraceableCacheAdapter::class,
            \get_class($cacheAdapter),
        ];

        yield 'Tag-aware cache pool adapter service inheriting parent service and overriding class' => [
            [
                'app.cache.parent' => new Definition(\get_class($cacheAdapter)),
                'app.cache' => (new ChildDefinition('app.cache.parent'))
                    ->setClass(\get_class($tagAwareCacheAdapter))
                    ->setPublic(true)
                    ->addTag('cache.pool'),
            ],
            TraceableTagAwareCacheAdapter::class,
            \get_class($tagAwareCacheAdapter),
        ];

        yield 'Tag-aware cache pool adapter service inheriting multiple parent services' => [
            [
                'app.cache.parent_1' => new Definition(\get_class($cacheAdapter)),
                'app.cache.parent_2' => (new ChildDefinition('app.cache.parent_1'))
                    ->setClass(\get_class($tagAwareCacheAdapter)),
                'app.cache' => (new ChildDefinition('app.cache.parent_2'))
                    ->setPublic(true)
                    ->addTag('cache.pool'),
            ],
            TraceableTagAwareCacheAdapter::class,
            \get_class($tagAwareCacheAdapter),
        ];

        yield 'Tag-aware cache pool adapter service inheriting parent service' => [
            [
                'app.cache.parent' => new Definition(\get_class($tagAwareCacheAdapter)),
                'app.cache' => (new ChildDefinition('app.cache.parent'))
                    ->setPublic(true)
                    ->addTag('cache.pool'),
            ],
            TraceableTagAwareCacheAdapter::class,
            \get_class($tagAwareCacheAdapter),
        ];
    }

    public function testProcessDoesNothingIfCachePoolServiceDefinitionIsAbstract(): void
    {
        $cacheAdapter = $this->createMock(AdapterInterface::class);
        $container = $this->createContainerBuilder(true);

        $container->register('app.cache', \get_class($cacheAdapter))
            ->setPublic(true)
            ->setAbstract(true)
            ->addTag('cache.pool');

        $container->compile();

        $this->assertFalse($container->hasDefinition('app.cache'));
    }

    public function testProcessDoesNothingIfConditionsForEnablingTracingAreMissing(): void
    {
        $container = $this->createContainerBuilder(false);
        $container->register('app.cache', AdapterInterface::class);
        $container->compile();

        $this->assertFalse($container->hasDefinition('app.cache.traceable.inner'));
    }

    private function createContainerBuilder(bool $isTracingActive): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new CacheTracingPass());
        $container->setParameter('sentry.tracing.cache.enabled', $isTracingActive);

        $container->register(HubInterface::class, HubInterface::class);
        $container->register('sentry.tracing.traceable_cache_adapter', TraceableCacheAdapter::class)
            ->setAbstract(true)
            ->setArgument(0, new Reference(HubInterface::class))
            ->setArgument(1, null);

        $container->register('sentry.tracing.traceable_tag_aware_cache_adapter', TraceableTagAwareCacheAdapter::class)
            ->setAbstract(true)
            ->setArgument(0, new Reference(HubInterface::class))
            ->setArgument(1, null);

        return $container;
    }
}
