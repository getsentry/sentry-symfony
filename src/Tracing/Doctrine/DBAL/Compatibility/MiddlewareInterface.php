<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver as DriverInterface;

/**
 * @internal
 */
interface MiddlewareInterface
{
    public function wrap(DriverInterface $driver): DriverInterface;
}
