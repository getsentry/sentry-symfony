<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->setParameter('env(SENTRY_TRACES_SAMPLE_RATE)', '0.5');
$container->setParameter('env(SENTRY_PROFILES_SAMPLE_RATE)', '');
$container->setParameter('sentry_traces_sample_rate', '%env(sentry_sample_rate:SENTRY_TRACES_SAMPLE_RATE)%');
$container->setParameter('sentry_profiles_sample_rate', '%env(sentry_sample_rate:SENTRY_PROFILES_SAMPLE_RATE)%');
$container->loadFromExtension('sentry', [
    'options' => [],
]);
