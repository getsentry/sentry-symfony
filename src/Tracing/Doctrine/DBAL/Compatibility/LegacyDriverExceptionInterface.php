<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver\DriverException;

if (interface_exists(DriverException::class)) {
    interface LegacyDriverExceptionInterface extends DriverException
    {
    }
} else {
    class_alias(DriverExceptionInterface::class, __NAMESPACE__ . '\LegacyDriverExceptionInterface');
}
