<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\VersionAwarePlatformDriver;
use Sentry\State\HubInterface;

/**
 * This is a simple implementation of the {@see Driver} interface that decorates
 * an existing driver to support distributed tracing capabilities. This implementation
 * is compatible with all versions of Doctrine DBAL >= 3.0.
 *
 * @internal
 */
final class TracingDriverForV3 implements Driver, VersionAwarePlatformDriver
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var Driver|VersionAwarePlatformDriver The instance of the decorated driver
     */
    private $decoratedDriver;

    /**
     * @param HubInterface $hub             The current hub
     * @param Driver       $decoratedDriver The instance of the driver to decorate
     */
    public function __construct(HubInterface $hub, Driver $decoratedDriver)
    {
        $this->hub = $hub;
        $this->decoratedDriver = $decoratedDriver;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(array $params): TracingDriverConnection
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
    public function getDatabasePlatform(): AbstractPlatform
    {
        return $this->decoratedDriver->getDatabasePlatform();
    }

    /**
     * {@inheritdoc}
     */
    public function getSchemaManager(Connection $conn, AbstractPlatform $platform): AbstractSchemaManager
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

    /**
     * {@inheritdoc}
     */
    public function createDatabasePlatformForVersion($version): AbstractPlatform
    {
        if ($this->decoratedDriver instanceof VersionAwarePlatformDriver) {
            return $this->decoratedDriver->createDatabasePlatformForVersion($version);
        }

        return $this->getDatabasePlatform();
    }
}
