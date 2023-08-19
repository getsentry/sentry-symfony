<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->setParameter('env(SENTRY_RELEASE)', '1.0.x-dev');
$container->loadFromExtension('sentry', []);
