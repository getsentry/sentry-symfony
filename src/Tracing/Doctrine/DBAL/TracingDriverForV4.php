<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection\StaticServerVersionProvider;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * This is a simple implementation of the {@see Driver} interface that decorates
 * an existing driver to support distributed tracing capabilities. This implementation
 * is compatible with all versions of Doctrine DBAL >= 4.0.
 *
 * @internal
 *
 * @psalm-import-type Params from \Doctrine\DBAL\DriverManager
 */
final class TracingDriverForV4 extends AbstractDriverMiddleware
{
    /**
     * @var TracingDriverConnectionFactoryInterface The connection factory
     */
    private $connectionFactory;

    /**
     * Constructor.
     *
     * @param TracingDriverConnectionFactoryInterface $connectionFactory The connection factory
     * @param Driver                                  $decoratedDriver   The instance of the driver to decorate
     */
    public function __construct(TracingDriverConnectionFactoryInterface $connectionFactory, Driver $decoratedDriver)
    {
        parent::__construct($decoratedDriver);

        $this->connectionFactory = $connectionFactory;
    }

    /**
     * {@inheritdoc}
     *
     * @psalm-param Params $params All connection parameters.
     */
    public function connect(array $params): TracingDriverConnectionInterface
    {
        $connection = parent::connect($params);
        $versionProvider = new StaticServerVersionProvider($connection->getServerVersion());

        return $this->connectionFactory->create(
            $connection,
            $this->getDatabasePlatform($versionProvider),
            $params
        );
    }
}
