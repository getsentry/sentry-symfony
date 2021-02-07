<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Sentry\State\HubInterface;

/**
 * This is a simple implementation of the {@see DriverInterface} interface that
 * decorates an existing driver to support distributed tracing capabilities.
 */
final class TracingDriver implements DriverInterface
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var DriverInterface The instance of the decorated driver
     */
    private $decoratedDriver;

    /**
     * Constructor.
     *
     * @param HubInterface    $hub             The current hub
     * @param DriverInterface $decoratedDriver The instance of the driver to decorate
     */
    public function __construct(HubInterface $hub, DriverInterface $decoratedDriver)
    {
        $this->hub = $hub;
        $this->decoratedDriver = $decoratedDriver;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(array $params)
    {
        return new TracingDriverConnection(
            $this->hub,
            $this->decoratedDriver->connect($params),
            $this->decoratedDriver->getDatabasePlatform()->getName(),
            $params
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabasePlatform()
    {
        return $this->decoratedDriver->getDatabasePlatform();
    }

    /**
     * {@inheritdoc}
     */
    public function getSchemaManager(Connection $conn, AbstractPlatform $platform)
    {
        return $this->decoratedDriver->getSchemaManager($conn, $platform);
    }

    /**
     * {@inheritdoc}
     */
    public function getExceptionConverter(): ExceptionConverter
    {
        return $this->decoratedDriver->getExceptionConverter();
    }
}
