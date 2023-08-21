<?php

declare(strict_types=1);

use Sentry\SentryBundle\Tests\DependencyInjection\Fixtures\StubEnvVarLoader;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $container->extension('sentry', []);

    $container->services()
        ->set(StubEnvVarLoader::class)
        ->tag('container.env_var_loader')
        ->args([['SENTRY_RELEASE' => '1.0.x-dev']])

        ->set(EnvVarProcessor::class)
        ->tag('container.env_var_processor')
        ->args([
            service('service_container'),
            tagged_iterator('container.env_var_loader'),
        ]);
};
