<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->setParameter('env(REGISTER_ERROR_LISTENER)', 'false');
$container->loadFromExtension('sentry', [
    'register_error_listener' => '%env(bool:REGISTER_ERROR_LISTENER)%',
]);
