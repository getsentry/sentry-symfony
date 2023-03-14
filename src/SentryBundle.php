<?php

declare(strict_types=1);

namespace Sentry\SentryBundle;

use Sentry\SentryBundle\DependencyInjection\Compiler\CacheTracingPass;
use Sentry\SentryBundle\DependencyInjection\Compiler\DbalTracingPass;
use Sentry\SentryBundle\DependencyInjection\Compiler\HttpClientTracingPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SentryBundle extends Bundle
{
    public const SDK_IDENTIFIER = 'sentry.php.symfony';

    public const SDK_VERSION = '4.7.0';

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new DbalTracingPass());
        $container->addCompilerPass(new CacheTracingPass());
        $container->addCompilerPass(new HttpClientTracingPass());
    }
}
