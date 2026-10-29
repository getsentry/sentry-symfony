<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App;

use Symfony\Component\Config\Loader\LoaderInterface;

class KernelWithTraceIgnoreStatusCodes extends Kernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(__DIR__ . '/tracing.yml');
        $loader->load(__DIR__ . '/trace_ignore_status_codes.yml');
    }
}
