<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App;

use Symfony\Component\Config\Loader\LoaderInterface;

class KernelWithMonologErrorsAndExceptionLogChannel extends KernelWithMonologErrors
{
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(static function ($container): void {
            $container->loadFromExtension('framework', ['exceptions' => [\RuntimeException::class => ['log_channel' => 'errors']]]);
            $container->loadFromExtension('monolog', ['channels' => ['errors']]);
        });
    }
}
