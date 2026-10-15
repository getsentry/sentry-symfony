<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App;

use Symfony\Component\Config\Loader\LoaderInterface;

/**
 * EXPERIMENT: Monolog handler enabled on top of the default listeners.
 */
class KernelWithMonologAndListeners extends Kernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(static function ($container): void {
            $container->loadFromExtension('sentry', ['register_monolog_handler' => true]);
        });
    }
}
