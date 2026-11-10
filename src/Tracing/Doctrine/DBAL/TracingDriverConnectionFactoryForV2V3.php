<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Sentry\SentryBundle\Sentry;

/**
 * @internal
 */
final class TracingDriverConnectionFactoryForV2V3 implements TracingDriverConnectionFactoryInterface
{
    /**
     * @var Sentry
     */
    private $sentry;

    /**
     * @var bool Whether prepare spans should be ignored
     */
    private $ignorePrepareSpans;

    /**
     * Constructor.
     *
     * @param Sentry $sentry
     * @param bool   $ignorePrepareSpans Whether prepare spans should be ignored
     */
    public function __construct(Sentry $sentry, bool $ignorePrepareSpans = false)
    {
        $this->sentry = $sentry;
        $this->ignorePrepareSpans = $ignorePrepareSpans;
    }

    /**
     * {@inheritdoc}
     */
    public function create(Connection $connection, AbstractPlatform $databasePlatform, array $params): TracingDriverConnectionInterface
    {
        $tracingDriverConnection = new TracingDriverConnection(
            $this->sentry,
            $connection,
            $this->getDatabasePlatform($databasePlatform),
            $params,
            $this->ignorePrepareSpans
        );

        if ($connection instanceof ServerInfoAwareConnection) {
            $tracingDriverConnection = new TracingServerInfoAwareDriverConnection($tracingDriverConnection);
        }

        return $tracingDriverConnection;
    }

    private function getDatabasePlatform(AbstractPlatform $databasePlatform): string
    {
        // https://github.com/open-telemetry/opentelemetry-specification/blob/33113489fb5a1b6da563abb4ffa541447b87f515/specification/trace/semantic_conventions/database.md#connection-level-attributes
        switch (true) {
            case $databasePlatform instanceof AbstractMySQLPlatform:
            case $databasePlatform instanceof MySqlPlatform:
                return 'mysql';

            case $databasePlatform instanceof DB2Platform:
                return 'db2';

            case $databasePlatform instanceof OraclePlatform:
                return 'oracle';

            case $databasePlatform instanceof PostgreSQLPlatform:
                return 'postgresql';

            case $databasePlatform instanceof SqlitePlatform:
                return 'sqlite';

            case $databasePlatform instanceof SQLServerPlatform:
                return 'mssql';

            default:
                return 'other_sql';
        }
    }
}
