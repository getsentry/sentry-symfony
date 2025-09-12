<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App;

use Symfony\Component\Config\Loader\LoaderInterface;

class KernelWithExtraConfig extends Kernel
{
    /**
     * @var string[]
     */
    private $extraConfigFiles;

    /**
     * @param string[] $extraConfigFiles
     */
    public function __construct(array $extraConfigFiles)
    {
        parent::__construct('test', true);
        $this->extraConfigFiles = $extraConfigFiles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        foreach ($this->extraConfigFiles as $file) {
            $loader->load($file);
        }
    }
}
