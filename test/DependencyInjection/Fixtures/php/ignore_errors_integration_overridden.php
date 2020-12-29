<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'options' => [
        'integrations' => [
            'Sentry\\Integration\\IgnoreErrorsIntegration',
        ],
    ],
]);
