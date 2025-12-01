<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class XmlSentryExtensionTest extends SentryExtensionTest
{
    protected function loadFixture(ContainerBuilder $container, string $fixtureFile): void
    {
        if (!class_exists(XmlFileLoader::class)) {
            $this->markTestSkipped('XML Config files are not supported in Symfony 8.x and above.');
        }
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/Fixtures/xml'));
        $loader->load($fixtureFile . '.xml');
    }
}
