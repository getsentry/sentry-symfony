<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\ServerInfoAwareConnection;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;

/**
 * This is a simple implementation of the {@see ServerInfoAwareConnection} interface
 * that forwards all method calls to the connection being decorated.
 *
 * @internal
 */
final class TracingServerInfoAwareDriverConnection implements TracingDriverConnectionInterface, ServerInfoAwareConnection
{
    /**
     * @var TracingDriverConnectionInterface The decorated connection
     */
    private $decoratedConnection;

    /**
     * Constructor.
     *
     * @param TracingDriverConnectionInterface $decoratedConnection The decorated connection
     */
    public function __construct(TracingDriverConnectionInterface $decoratedConnection)
    {
        $this->decoratedConnection = $decoratedConnection;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($sql): Statement
    {
        return $this->decoratedConnection->prepare($sql);
    }

    /**
     * {@inheritdoc}
     */
    public function query(?string $sql = null, ...$args): Result
    {
        return $this->decoratedConnection->query($sql, ...$args);
    }

    /**
     * {@inheritdoc}
     *
     * @return mixed
     */
    public function quote($value, $type = ParameterType::STRING)
    {
        return $this->decoratedConnection->quote($value, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function exec($sql): int
    {
        return $this->decoratedConnection->exec($sql);
    }

    /**
     * {@inheritdoc}
     *
     * @return string|int|false
     */
    public function lastInsertId($name = null)
    {
        return $this->decoratedConnection->lastInsertId($name);
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): bool
    {
        return $this->decoratedConnection->beginTransaction();
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): bool
    {
        return $this->decoratedConnection->commit();
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack(): bool
    {
        return $this->decoratedConnection->rollBack();
    }

    /**
     * {@inheritdoc}
     */
    public function getServerVersion(): string
    {
        $wrappedConnection = $this->getWrappedConnection();

        if (!$wrappedConnection instanceof ServerInfoAwareConnection) {
            throw new \BadMethodCallException(\sprintf('The wrapped connection must be an instance of the "%s" interface.', ServerInfoAwareConnection::class));
        }

        return $wrappedConnection->getServerVersion();
    }

    /**
     * {@inheritdoc}
     *
     * @return resource|object
     */
    public function getNativeConnection()
    {
        if (!method_exists($this->decoratedConnection, 'getNativeConnection')) {
            throw new \BadMethodCallException(\sprintf('The connection "%s" does not support accessing the native connection.', \get_class($this->decoratedConnection)));
        }

        return $this->decoratedConnection->getNativeConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function requiresQueryForServerVersion(): bool
    {
        $wrappedConnection = $this->getWrappedConnection();

        if (!$wrappedConnection instanceof ServerInfoAwareConnection) {
            throw new \BadMethodCallException(\sprintf('The wrapped connection must be an instance of the "%s" interface.', ServerInfoAwareConnection::class));
        }

        if (!method_exists($wrappedConnection, 'requiresQueryForServerVersion')) {
            throw new \BadMethodCallException(\sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
        }

        return $wrappedConnection->requiresQueryForServerVersion();
    }

    /**
     * {@inheritdoc}
     */
    public function errorCode(): ?string
    {
        if (method_exists($this->decoratedConnection, 'errorCode')) {
            return $this->decoratedConnection->errorCode();
        }

        throw new \BadMethodCallException(\sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
    }

    /**
     * {@inheritdoc}
     */
    public function errorInfo(): array
    {
        if (method_exists($this->decoratedConnection, 'errorInfo')) {
            return $this->decoratedConnection->errorInfo();
        }

        throw new \BadMethodCallException(\sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
    }

    public function getWrappedConnection(): Connection
    {
        return $this->decoratedConnection->getWrappedConnection();
    }
}
