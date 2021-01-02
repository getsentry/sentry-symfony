<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'options' => [
        'error_types' => E_ALL & ~(E_NOTICE | E_STRICT | E_DEPRECATED),
    ],
]);
