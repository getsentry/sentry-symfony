<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as SymfonyKernel;
use Symfony\Component\Messenger\MessageBusInterface;

class Kernel extends SymfonyKernel
{
    /** @var string */
    private static $cacheDir = null;

    /**
     * @return BundleInterface[]
     */
    public function registerBundles(): array
    {
        $bundles = [
            new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new \Symfony\Bundle\MonologBundle\MonologBundle(),
            new \Sentry\SentryBundle\SentryBundle(),
        ];

        if (class_exists(DoctrineBundle::class)) {
            $bundles[] = new DoctrineBundle();
        }

        return $bundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__ . '/config.yml');

        if (self::VERSION_ID >= 50000) {
            $loader->load(__DIR__ . '/deprecations_for_5.yml');
        }

        if (interface_exists(MessageBusInterface::class) && self::VERSION_ID >= 40300) {
            $loader->load(__DIR__ . '/messenger.yml');
        }

        if (class_exists(DoctrineBundle::class)) {
            $loader->load(__DIR__ . '/doctrine.yml');
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->setParameter('routing_config_dir', __DIR__);

        parent::build($container);
    }

    public function getCacheDir(): string
    {
        if (self::$cacheDir === null) {
            self::$cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('sentry-symfony-');
        }

        return self::$cacheDir;
    }
}
