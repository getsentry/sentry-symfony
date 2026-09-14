<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->extension('sentry', [
        'options' => [
            'send_default_pii' => false,
            'data_collection' => ['database_query_data' => true],
        ],
    ]);
};
