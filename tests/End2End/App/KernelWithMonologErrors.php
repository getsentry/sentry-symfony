<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * EXPERIMENT: errors are reported only through the auto-registered Monolog handler.
 *
 * Mirrors {@see Kernel::registerContainerConfiguration()}, but with a config file that doesn't ignore fatal errors.
 */
class KernelWithMonologErrors extends Kernel
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__ . '/config_monolog_errors.yml');

        if (self::VERSION_ID >= 50000) {
            $loader->load(__DIR__ . '/deprecations_for_5.yml');
        }

        if (self::VERSION_ID >= 50400 && self::VERSION_ID <= 60000 && interface_exists(MessageBusInterface::class)) {
            $loader->load(__DIR__ . '/deprecations_for_54.yml');
        }

        if (self::VERSION_ID >= 60000) {
            $loader->load(__DIR__ . '/deprecations_for_6.yml');
        }

        if (self::VERSION_ID >= 60400) {
            $loader->load(__DIR__ . '/deprecations_for_64.yml');
        }

        if (interface_exists(MessageBusInterface::class) && self::VERSION_ID >= 40300) {
            $loader->load(__DIR__ . '/messenger.yml');
            $loader->load(static function ($container): void {
                $container->loadFromExtension('sentry', ['messenger' => ['enabled' => false]]);
            });
        }

        if (class_exists(DoctrineBundle::class)) {
            $loader->load(__DIR__ . '/doctrine.yml');
        }
    }
}
