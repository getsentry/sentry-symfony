<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class XmlSentryExtensionTest extends SentryExtensionTest
{
    protected function loadFixture(ContainerBuilder $container, string $fixtureFile): void
    {
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Fixtures/xml'));
        $loader->load($fixtureFile . '.xml');
    }
}
