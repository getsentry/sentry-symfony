<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\DriverException as LegacyDriverExceptionInterface;
use Doctrine\DBAL\Driver\ExceptionConverterDriver as ExceptionConverterDriverInterface;
use Doctrine\DBAL\Driver\ResultStatement;
use Doctrine\DBAL\Exception\DriverException as DBALDriverException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\VersionAwarePlatformDriver as VersionAwarePlatformDriverInterface;
use Sentry\State\HubInterface;

/**
 * This is a simple implementation of the {@see DriverInterface} interface that
 * decorates an existing driver to support distributed tracing capabilities.
 * This implementation IS and MUST be compatible with all versions of Doctrine
 * DBAL >= 2.13.
 *
 * @internal
 */
final class TracingDriver implements DriverInterface, VersionAwarePlatformDriverInterface, ExceptionConverterDriverInterface
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var DriverInterface|VersionAwarePlatformDriverInterface|ExceptionConverterDriverInterface The instance of the decorated driver
     */
    private $decoratedDriver;

    /**
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
    public function connect(array $params, $username = null, $password = null, array $driverOptions = [])
    {
        return new TracingDriverConnection(
            $this->hub,
            $this->decoratedDriver->connect($params, $username, $password, $driverOptions),
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
    public function getSchemaManager(Connection $conn, ?AbstractPlatform $platform = null)
    {
        return $this->decoratedDriver->getSchemaManager($conn, $platform);
    }

    /**
     * {@inheritdoc}
     */
    public function getExceptionConverter(): ExceptionConverter
    {
        if (method_exists($this->decoratedDriver, 'getExceptionConverter')) {
            return $this->decoratedDriver->getExceptionConverter();
        }

        throw new \BadMethodCallException(sprintf('The %s() method is not supported on Doctrine DBAL 2.x.', __METHOD__));
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        if (method_exists($this->decoratedDriver, 'getName')) {
            return $this->decoratedDriver->getName();
        }

        throw new \BadMethodCallException(sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
    }

    /**
     * {@inheritdoc}
     */
    public function getDatabase(Connection $conn): string
    {
        if (method_exists($this->decoratedDriver, 'getDatabase')) {
            return $this->decoratedDriver->getDatabase($conn);
        }

        throw new \BadMethodCallException(sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
    }

    /**
     * {@inheritdoc}
     */
    public function createDatabasePlatformForVersion($version): AbstractPlatform
    {
        if ($this->decoratedDriver instanceof VersionAwarePlatformDriverInterface) {
            return $this->decoratedDriver->createDatabasePlatformForVersion($version);
        }

        return $this->getDatabasePlatform();
    }

    /**
     * {@inheritdoc}
     */
    public function convertException($message, LegacyDriverExceptionInterface $exception): DBALDriverException
    {
        if (!interface_exists(ResultStatement::class)) {
            throw new \BadMethodCallException(sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
        }

        if ($this->decoratedDriver instanceof ExceptionConverterDriverInterface) {
            return $this->decoratedDriver->convertException($message, $exception);
        }

        return new DBALDriverException($message, $exception);
    }
}
