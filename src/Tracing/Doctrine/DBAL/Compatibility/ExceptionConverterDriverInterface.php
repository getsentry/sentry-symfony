<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver as DriverInterface;

/**
 * @internal
 */
interface ExceptionConverterDriverInterface extends DriverInterface
{
}
