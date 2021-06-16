<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class CacheTracingPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->getParameter('sentry.tracing.cache.enabled')) {
            return;
        }

        foreach ($container->findTaggedServiceIds('cache.pool') as $serviceId => $tags) {
            $cachePoolDefinition = $container->getDefinition($serviceId);

            if ($cachePoolDefinition->isAbstract()) {
                continue;
            }

            $definitionClass = $this->resolveDefinitionClass($container, $cachePoolDefinition);

            if (null === $definitionClass) {
                continue;
            }

            if (is_subclass_of($definitionClass, TagAwareAdapterInterface::class)) {
                $traceableCachePoolDefinition = new ChildDefinition('sentry.tracing.traceable_tag_aware_cache_adapter');
            } else {
                $traceableCachePoolDefinition = new ChildDefinition('sentry.tracing.traceable_cache_adapter');
            }

            $traceableCachePoolDefinition->setDecoratedService($serviceId);
            $traceableCachePoolDefinition->replaceArgument(1, new Reference($serviceId . '.traceable.inner'));

            $container->setDefinition($serviceId . '.traceable', $traceableCachePoolDefinition);
        }
    }

    private function resolveDefinitionClass(ContainerBuilder $container, Definition $definition): ?string
    {
        $class = $definition->getClass();

        while ($definition instanceof ChildDefinition) {
            $definition = $container->findDefinition($definition->getParent());
            $class = $class ?: $definition->getClass();
        }

        return $class;
    }
}
