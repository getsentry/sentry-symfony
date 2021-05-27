<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver\Middleware as DoctrineMiddlewareInterface;

if (interface_exists(DoctrineMiddlewareInterface::class)) {
    class_alias(DoctrineMiddlewareInterface::class, __NAMESPACE__ . '\MiddlewareInterface');
} else {
    /**
     * @internal
     */
    interface MiddlewareInterface
    {
        public function wrap(DriverInterface $driver): DriverInterface;
    }
}
