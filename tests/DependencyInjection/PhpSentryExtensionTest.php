<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class PhpSentryExtensionTest extends SentryExtensionTest
{
    protected function loadFixture(ContainerBuilder $container, string $fixtureFile): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/Fixtures/php'));
        $loader->load($fixtureFile . '.php');
    }
}
