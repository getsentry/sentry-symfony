<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL\Fixture;

use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionInterface;

interface NativeDriverConnectionInterfaceStub extends TracingDriverConnectionInterface
{
    /**
     * @return object|resource
     */
    public function getNativeConnection();
}
