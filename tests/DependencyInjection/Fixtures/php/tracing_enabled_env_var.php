<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->setParameter('env(SENTRY_TRACING)', 'false');
$container->loadFromExtension('sentry', [
    'tracing' => [
        'enabled' => '%env(bool:SENTRY_TRACING)%',
    ],
]);
