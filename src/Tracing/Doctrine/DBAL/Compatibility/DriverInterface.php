<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver as DoctrineDriverInterface;

if (interface_exists(DoctrineDriverInterface::class)) {
    class_alias(DoctrineDriverInterface::class, __NAMESPACE__ . '\DriverInterface');
} else {
    /**
     * @internal
     */
    interface DriverInterface
    {
    }
}
