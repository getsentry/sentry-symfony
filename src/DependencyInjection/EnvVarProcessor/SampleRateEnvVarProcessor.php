<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\EnvVarProcessor;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Resolves an env var to a sample rate, so that `%env(sample_rate:FOO)%`
 * resolves to null when the env var is empty or not set. The `float:` env var
 * processor can't express this, but a null `traces_sample_rate` is the only
 * value that disables tracing.
 *
 * @internal
 */
final class SampleRateEnvVarProcessor implements EnvVarProcessorInterface
{
    /**
     * @param string $prefix
     * @param string $name
     */
    public function getEnv($prefix, $name, \Closure $getEnv): ?float
    {
        try {
            $value = $getEnv($name);
        } catch (EnvNotFoundException $exception) {
            return null;
        }

        if (null === $value || '' === $value) {
            return null;
        }

        if (!is_numeric($value)) {
            throw new RuntimeException(\sprintf('Non-numeric env var "%s" cannot be used as a sample rate.', $name));
        }

        $sampleRate = (float) $value;

        if ($sampleRate < 0.0 || $sampleRate > 1.0) {
            throw new RuntimeException(\sprintf('The env var "%s" must be a sample rate between 0 and 1, but got %s.', $name, $value));
        }

        return $sampleRate;
    }

    /**
     * @return array<string, string>
     */
    public static function getProvidedTypes(): array
    {
        return [
            'sample_rate' => 'float',
            'sentry_sample_rate' => 'float',
        ];
    }
}
