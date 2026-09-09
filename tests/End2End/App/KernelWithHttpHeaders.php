<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Session\Storage\MockFileSessionStorageFactory;

/**
 * @internal
 */
final class KernelWithHttpHeaders extends KernelWithTracing
{
    /**
     * @var array<string, mixed>
     */
    private $options;

    /**
     * @var bool
     */
    private $session;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options, bool $session = false)
    {
        parent::__construct('test', false);
        $this->options = $options;
        $this->session = $session;
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('sentry', ['options' => $this->options]);
            if ($this->session) {
                $storage = class_exists(MockFileSessionStorageFactory::class)
                    ? ['storage_factory_id' => 'session.storage.factory.mock_file']
                    : ['storage_id' => 'session.storage.mock_file'];
                $container->loadFromExtension('framework', ['session' => $storage]);
            }
        }, 'closure');
    }
}
