<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;

interface TracingDriverConnectionFactoryInterface
{
    /**
     * Creates an instance of a driver connection which is decorated to trace
     * the performances of the queries.
     *
     * @param Connection           $connection       The connection to wrap
     * @param AbstractPlatform     $databasePlatform The database platform
     * @param array<string, mixed> $params           The params of the connection
     */
    public function create(Connection $connection, AbstractPlatform $databasePlatform, array $params): TracingDriverConnectionInterface;
}
