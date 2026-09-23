<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'options' => [
        'data_collection' => [
            'http_headers' => [
                'mode' => 'allowList',
                'terms' => ['x-request-id'],
            ],
            'http_bodies' => [],
            'stack_frame_variables' => false,
        ],
    ],
]);
