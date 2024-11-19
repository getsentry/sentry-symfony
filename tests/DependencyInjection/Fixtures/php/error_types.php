<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'options' => [
        // 2048 is \E_STRICT which has been deprecated in PHP 8.4 so we should not reference it directly to prevent deprecation notices
        'error_types' => \E_ALL & ~(\E_NOTICE | 2048 | \E_DEPRECATED),
    ],
]);
