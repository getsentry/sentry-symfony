<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\SentryBundle\DependencyInjection\EnvVarProcessor\SampleRateEnvVarProcessor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * Symfony lets the last registered env var processor win when several of them
 * provide the same prefix. The bundle registers its processor before any other
 * service, so an existing `sample_rate` env var processor keeps its prefix and
 * `sentry_sample_rate` has to be used instead. This pass logs when that happens.
 *
 * @internal
 */
final class SampleRateEnvVarProcessorPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(SampleRateEnvVarProcessor::class)) {
            return;
        }

        $sampleRateProviderId = null;

        foreach ($container->findTaggedServiceIds('container.env_var_processor') as $id => $tags) {
            $reflectionClass = $container->getReflectionClass($container->getDefinition($id)->getClass() ?? $id, false);

            if (null === $reflectionClass || !$reflectionClass->implementsInterface(EnvVarProcessorInterface::class)) {
                continue;
            }

            /** @var class-string<EnvVarProcessorInterface> $class */
            $class = $reflectionClass->getName();

            if (\array_key_exists('sample_rate', $class::getProvidedTypes())) {
                $sampleRateProviderId = $id;
            }
        }

        if (null === $sampleRateProviderId || SampleRateEnvVarProcessor::class === $sampleRateProviderId) {
            return;
        }

        $container->log($this, \sprintf('The "sample_rate" env var prefix is already provided by the "%s" service, use the "sentry_sample_rate" env var prefix instead.', $sampleRateProviderId));
    }
}
