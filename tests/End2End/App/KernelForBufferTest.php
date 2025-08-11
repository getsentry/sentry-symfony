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
        parent::registerContainerConfiguration($loader);
        $loader->load(__DIR__ . '/config_buffer_test.yml');

        if (!$this->supportsHubId()) {
            $loader->load(__DIR__ . '/config_buffer_test_php72.yml');
        }
    }

    /**
     * Monolog Bundle supports hub_id from version 3.7 onwards.
     */
    private function supportsHubId(): bool
    {
        try {
            if (class_exists('Composer\InstalledVersions')) {
                $version = \Composer\InstalledVersions::getVersion('symfony/monolog-bundle');
                if ($version && version_compare($version, '3.7.0', '>=')) {
                    return true;
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
