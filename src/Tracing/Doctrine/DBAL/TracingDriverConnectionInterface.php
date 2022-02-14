<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;

/**
 * @method resource|object getNativeConnection()
 */
interface TracingDriverConnectionInterface extends Connection
{
    public function getWrappedConnection(): Connection;
}
