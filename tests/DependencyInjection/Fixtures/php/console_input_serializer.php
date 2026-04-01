<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;

/** @var ContainerBuilder $container */
$container->loadFromExtension('sentry', [
    'options' => [
        'class_serializers' => [
            'Symfony\\Component\\Console\\Input\\InputInterface' => 'Sentry\\SentryBundle\\Serializer\\ConsoleInputSerializer',
        ],
    ],
]);
