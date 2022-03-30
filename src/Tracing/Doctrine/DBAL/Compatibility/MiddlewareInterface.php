<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Middleware as DoctrineMiddlewareInterface;

if (interface_exists(DoctrineMiddlewareInterface::class)) {
    /**
     * @internal
     */
    interface MiddlewareInterface extends DoctrineMiddlewareInterface
    {
    }
} else {
    /**
     * @internal
     */
    interface MiddlewareInterface
    {
        public function wrap(DriverInterface $driver): DriverInterface;
    }
}
