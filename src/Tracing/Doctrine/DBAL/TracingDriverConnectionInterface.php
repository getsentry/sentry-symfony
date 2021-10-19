<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;

interface TracingDriverConnectionInterface extends Connection
{
    public function getWrappedConnection(): Connection;
}
