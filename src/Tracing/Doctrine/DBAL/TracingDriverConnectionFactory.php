<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Sentry\State\HubInterface;

/**
 * @internal
 */
final class TracingDriverConnectionFactory implements TracingDriverConnectionFactoryInterface
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * Constructor.
     *
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * {@inheritdoc}
     */
    public function create(Connection $connection, AbstractPlatform $databasePlatform, array $params): TracingDriverConnectionInterface
    {
        $tracingDriverConnection = new TracingDriverConnection(
            $this->hub,
            $connection,
            $databasePlatform->getName(),
            $params
        );

        if ($connection instanceof ServerInfoAwareConnection) {
            $tracingDriverConnection = new TracingServerInfoAwareDriverConnection($tracingDriverConnection);
        }

        return $tracingDriverConnection;
    }
}
