<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\DriverException;
use Doctrine\DBAL\Driver\ExceptionConverterDriver;
use Doctrine\DBAL\Exception\DriverException as DBALDriverException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\VersionAwarePlatformDriver;

/**
 * This is a simple implementation of the {@see Driver} interface that decorates
 * an existing driver to support distributed tracing capabilities. This implementation
 * is compatible only with DBAL version < 3.0.
 *
 * @internal
 */
final class TracingDriverForV2 implements Driver, VersionAwarePlatformDriver, ExceptionConverterDriver
{
    /**
     * @var TracingDriverConnectionFactoryInterface
     */
    private $connectionFactory;

    /**
     * @var Driver|VersionAwarePlatformDriver|ExceptionConverterDriver The instance of the decorated driver
     */
    private $decoratedDriver;

    /**
     * @param TracingDriverConnectionFactoryInterface $connectionFactory The connection factory
     * @param Driver                                  $decoratedDriver   The instance of the driver to decorate
     */
    public function __construct(TracingDriverConnectionFactoryInterface $connectionFactory, Driver $decoratedDriver)
    {
        $this->decoratedDriver = $decoratedDriver;
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function connect(array $params, $username = null, $password = null, array $driverOptions = []): TracingDriverConnectionInterface
    {
        return $this->connectionFactory->create(
            $this->decoratedDriver->connect($params, $username, $password, $driverOptions),
            $this->decoratedDriver->getDatabasePlatform(),
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
    public function getSchemaManager(Connection $conn, ?AbstractPlatform $platform = null): AbstractSchemaManager
    {
        return $this->decoratedDriver->getSchemaManager($conn, $platform);
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return $this->decoratedDriver->getName();
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabase(Connection $conn): ?string
    {
        return $this->decoratedDriver->getDatabase($conn);
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

    /**
     * {@inheritdoc}
     */
    public function convertException($message, DriverException $exception): DBALDriverException
    {
        if ($this->decoratedDriver instanceof ExceptionConverterDriver) {
            return $this->decoratedDriver->convertException($message, $exception);
        }

        return new DBALDriverException($message, $exception);
    }
}
