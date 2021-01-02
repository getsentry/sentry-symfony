<?php

declare(strict_types=1);

use Monolog\Logger;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'monolog' => [
        'level' => Logger::ERROR,
        'bubble' => false,
    ],
]);
