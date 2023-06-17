<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;

/**
 * This is a simple implementation of the {@see Driver} interface that decorates
 * an existing driver to support distributed tracing capabilities. This implementation
 * is compatible with all versions of Doctrine DBAL >= 3.2.
 *
 * @internal
 *
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager as ConnectionParams
 */
final class TracingDriverForV32 implements Driver
{
    /**
     * @var TracingDriverConnectionFactoryInterface The connection factory
     */
    private $connectionFactory;

    /**
     * @var Driver The instance of the decorated driver
     */
    private $decoratedDriver;

    /**
     * Constructor.
     *
     * @param TracingDriverConnectionFactoryInterface $connectionFactory The connection factory
     * @param Driver                                  $decoratedDriver   The instance of the driver to decorate
     */
    public function __construct(TracingDriverConnectionFactoryInterface $connectionFactory, Driver $decoratedDriver)
    {
        $this->connectionFactory = $connectionFactory;
        $this->decoratedDriver = $decoratedDriver;
    }

    /**
     * {@inheritdoc}
     *
     * @phpstan-param ConnectionParams $params
     */
    public function connect(array $params): TracingDriverConnectionInterface
    {
        return $this->connectionFactory->create(
            $this->decoratedDriver->connect($params),
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
     *
     * @phpstan-template T of AbstractPlatform
     *
     * @phpstan-param T $platform
     *
     * @phpstan-return AbstractSchemaManager<T>
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
     *
     * @phpstan-param string $version
     *
     * @phpstan-return AbstractPlatform
     */
    public function createDatabasePlatformForVersion($version): AbstractPlatform
    {
        if (method_exists($this->decoratedDriver, 'createDatabasePlatformForVersion')) {
            return $this->decoratedDriver->createDatabasePlatformForVersion($version);
        }

        return $this->getDatabasePlatform();
    }
}
