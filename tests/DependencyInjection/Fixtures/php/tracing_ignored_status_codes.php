<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'tracing' => [
        'ignored_http_status_codes' => [404, 405],
    ],
]);
