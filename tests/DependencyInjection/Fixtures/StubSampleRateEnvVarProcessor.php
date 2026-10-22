<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Fixtures;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

final class StubSampleRateEnvVarProcessor implements EnvVarProcessorInterface
{
    /**
     * @param string $prefix
     * @param string $name
     */
    public function getEnv($prefix, $name, \Closure $getEnv): string
    {
        return 'stub';
    }

    public static function getProvidedTypes(): array
    {
        return ['sample_rate' => 'string'];
    }
}
