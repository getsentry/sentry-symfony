<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App;

use Symfony\Component\Config\Loader\LoaderInterface;

/**
 * Test kernel specifically for buffer flush testing.
 * Uses a configuration that includes buffer handlers.
 */
class KernelForBufferTest extends Kernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__ . '/config_buffer_test.yml');
    }
}
