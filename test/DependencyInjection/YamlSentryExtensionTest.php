<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class YamlSentryExtensionTest extends SentryExtensionTest
{
    protected function loadFixture(ContainerBuilder $container, string $fixtureFile): void
    {
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/Fixtures/yml'));
        $loader->load($fixtureFile . '.yml');
    }
}
