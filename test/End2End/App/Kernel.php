<?php

namespace Sentry\SentryBundle\Test\End2End\App;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel as SymfonyKernel;
use Symfony\Component\Messenger\MessageBusInterface;

class Kernel extends SymfonyKernel
{
    /**
     * @return BundleInterface[]
     */
    public function registerBundles(): array
    {
        return [
            new \Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new \Symfony\Bundle\MonologBundle\MonologBundle(),
            new \Sentry\SentryBundle\SentryBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__ . '/config.yml');

        if (interface_exists(MessageBusInterface::class) && self::VERSION_ID >= 40300) {
            $loader->load(__DIR__ . '/messenger.yml');
        }
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->setParameter('routing_config_dir', __DIR__);

        parent::build($container);
    }
}
