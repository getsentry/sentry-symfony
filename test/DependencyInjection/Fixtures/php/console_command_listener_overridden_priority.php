<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'listener_priorities' => [
        'console' => -128,
        'console-terminate' => 64,
        'console-error' => 64,
    ],
]);
