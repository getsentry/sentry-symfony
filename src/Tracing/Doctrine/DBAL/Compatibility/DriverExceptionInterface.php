<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver\Exception as DriverException;

if (interface_exists(DriverException::class)) {
    class_alias(DriverException::class, __NAMESPACE__ . '\DriverExceptionInterface');
} else {
    /**
     * @internal
     */
    interface DriverExceptionInterface
    {
    }
}
