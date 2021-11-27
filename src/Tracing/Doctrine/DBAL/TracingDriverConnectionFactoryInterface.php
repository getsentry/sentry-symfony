<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;

/**
 * This interface defines a contract that must be implemented by all factories
 * supporting the creation of a Doctrine DBAL driver connection which is instrumented
 * to report performance information to Sentry.
 *
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager as ConnectionParams
 */
interface TracingDriverConnectionFactoryInterface
{
    /**
     * Creates an instance of a driver connection which is decorated to trace
     * the performances of the queries.
     *
     * @param Connection           $connection       The connection to wrap
     * @param AbstractPlatform     $databasePlatform The database platform
     * @param array<string, mixed> $params           The params of the connection
     *
     * @phpstan-param ConnectionParams $params
     */
    public function create(Connection $connection, AbstractPlatform $databasePlatform, array $params): TracingDriverConnectionInterface;
}
