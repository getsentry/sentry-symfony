<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Fixtures;

use Symfony\Component\DependencyInjection\EnvVarLoaderInterface;

final class StubEnvVarLoader implements EnvVarLoaderInterface
{
    /**
     * @var array<string, string>
     */
    private $envs = [];

    /**
     * @param array<string, string> $envs
     */
    public function __construct(array $envs)
    {
        $this->envs = $envs;
    }

    public function loadEnvVars(): array
    {
        return $this->envs;
    }
}
