<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\DB2Platform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Platforms\SQLServerPlatform;
use Sentry\SentryBundle\SentryManager;

/**
 * @internal
 */
final class TracingDriverConnectionFactoryForV4 implements TracingDriverConnectionFactoryInterface
{
    /**
     * @var SentryManager
     */
    private $sentry;

    /**
     * @var bool Whether prepare spans should be ignored
     */
    private $ignorePrepareSpans;

    /**
     * Constructor.
     *
     * @param SentryManager $sentry
     * @param bool          $ignorePrepareSpans Whether prepare spans should be ignored
     */
    public function __construct(SentryManager $sentry, bool $ignorePrepareSpans = false)
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

        return $tracingDriverConnection;
    }

    private function getDatabasePlatform(AbstractPlatform $databasePlatform): string
    {
        // https://github.com/open-telemetry/opentelemetry-specification/blob/33113489fb5a1b6da563abb4ffa541447b87f515/specification/trace/semantic_conventions/database.md#connection-level-attributes
        switch (true) {
            case $databasePlatform instanceof AbstractMySQLPlatform:
                return 'mysql';

            case $databasePlatform instanceof DB2Platform:
                return 'db2';

            case $databasePlatform instanceof OraclePlatform:
                return 'oracle';

            case $databasePlatform instanceof PostgreSQLPlatform:
                return 'postgresql';

            case $databasePlatform instanceof SQLitePlatform:
                return 'sqlite';

            case $databasePlatform instanceof SQLServerPlatform:
                return 'mssql';

            default:
                return 'other_sql';
        }
    }
}
