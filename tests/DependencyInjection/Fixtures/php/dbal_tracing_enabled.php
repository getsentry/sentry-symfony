<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'tracing' => [
        'dbal' => [
            'enabled' => true,
            'ignore_prepare_spans' => true,
            'connections' => ['default'],
        ],
    ],
]);
