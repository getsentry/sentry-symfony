<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final class KernelWithDatabaseDataCollection extends KernelWithTracing
{
    /**
     * @var array<string, mixed>
     */
    private $options;

    /**
     * @var bool
     */
    private $ignorePrepareSpans;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options, bool $ignorePrepareSpans = false)
    {
        parent::__construct('test', false);
        $this->options = $options;
        $this->ignorePrepareSpans = $ignorePrepareSpans;
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('sentry', [
                'options' => $this->options,
                'tracing' => [
                    'dbal' => [
                        'ignore_prepare_spans' => $this->ignorePrepareSpans,
                    ],
                ],
            ]);
        }, 'closure');
    }
}
