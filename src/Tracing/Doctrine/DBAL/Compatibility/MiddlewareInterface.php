<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver\Middleware as DoctrineMiddlewareInterface;

if (interface_exists(DoctrineMiddlewareInterface::class)) {
    /**
     * @internal
     */
    interface MiddlewareInterface extends DoctrineMiddlewareInterface
    {
        public function wrap(DriverInterface $driver): DriverInterface;
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
