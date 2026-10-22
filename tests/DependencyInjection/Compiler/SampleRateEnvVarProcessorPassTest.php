<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\SampleRateEnvVarProcessorPass;
use Sentry\SentryBundle\DependencyInjection\EnvVarProcessor\SampleRateEnvVarProcessor;
use Sentry\SentryBundle\Tests\DependencyInjection\Fixtures\StubSampleRateEnvVarProcessor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

final class SampleRateEnvVarProcessorPassTest extends TestCase
{
    public function testProcess(): void
    {
        $container = $this->createContainerBuilder();
        $container->compile(true);

        $this->assertSame(0.5, $container->getParameter('sample_rate'));
        $this->assertSame(0.5, $container->getParameter('sentry_sample_rate'));
        $this->assertSame([], $this->getSampleRateEnvVarProcessorPassLogs($container));
    }

    public function testProcessFallsBackToPrefixedEnvVarProcessorWhenSampleRateIsAlreadyProvided(): void
    {
        $container = $this->createContainerBuilder();
        $container->registerForAutoconfiguration(EnvVarProcessorInterface::class)
            ->addTag('container.env_var_processor');
        $container->register(StubSampleRateEnvVarProcessor::class)
            ->setAutoconfigured(true);
        $container->compile(true);

        $this->assertSame('stub', $container->getParameter('sample_rate'));
        $this->assertSame(0.5, $container->getParameter('sentry_sample_rate'));
        $this->assertSame([
            SampleRateEnvVarProcessorPass::class . ': The "sample_rate" env var prefix is already provided by the "' . StubSampleRateEnvVarProcessor::class . '" service, use the "sentry_sample_rate" env var prefix instead.',
        ], $this->getSampleRateEnvVarProcessorPassLogs($container));
    }

    public function testProcessDoesNothingWhenEnvVarProcessorIsNotRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->register(StubSampleRateEnvVarProcessor::class)
            ->addTag('container.env_var_processor');
        $container->addCompilerPass(new SampleRateEnvVarProcessorPass());
        $container->compile();

        $this->assertSame([], $this->getSampleRateEnvVarProcessorPassLogs($container));
    }

    private function createContainerBuilder(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('env(SENTRY_TRACES_SAMPLE_RATE)', '0.5');
        $container->setParameter('sample_rate', '%env(sample_rate:SENTRY_TRACES_SAMPLE_RATE)%');
        $container->setParameter('sentry_sample_rate', '%env(sentry_sample_rate:SENTRY_TRACES_SAMPLE_RATE)%');
        $container->register(SampleRateEnvVarProcessor::class)
            ->addTag('container.env_var_processor');
        $container->addCompilerPass(new SampleRateEnvVarProcessorPass());

        return $container;
    }

    /**
     * @return string[]
     */
    private function getSampleRateEnvVarProcessorPassLogs(ContainerBuilder $container): array
    {
        return array_values(array_filter($container->getCompiler()->getLog(), static function (string $log): bool {
            return SampleRateEnvVarProcessorPass::class === substr($log, 0, \strlen(SampleRateEnvVarProcessorPass::class));
        }));
    }
}
