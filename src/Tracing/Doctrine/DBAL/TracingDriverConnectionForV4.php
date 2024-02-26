<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;

/**
 * This implementation wraps a driver connection and adds distributed tracing
 * capabilities to Doctrine DBAL. This implementation IS and MUST be compatible
 * with all versions of Doctrine DBAL >= 4.0.
 *
 * @phpstan-import-type Params from \Doctrine\DBAL\DriverManager as ConnectionParams
 */
final class TracingDriverConnectionForV4 implements TracingDriverConnectionInterface
{
    /**
     * @internal
     */
    public const SPAN_OP_CONN_PREPARE = 'db.sql.prepare';

    /**
     * @internal
     */
    public const SPAN_OP_CONN_QUERY = 'db.sql.query';

    /**
     * @internal
     */
    public const SPAN_OP_CONN_EXEC = 'db.sql.exec';

    /**
     * @internal
     */
    public const SPAN_OP_CONN_BEGIN_TRANSACTION = 'db.sql.transaction.begin';

    /**
     * @internal
     */
    public const SPAN_OP_TRANSACTION_COMMIT = 'db.sql.transaction.commit';

    /**
     * @internal
     */
    public const SPAN_OP_TRANSACTION_ROLLBACK = 'db.sql.transaction.rollback';

    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var DriverConnectionInterface The decorated connection
     */
    private $decoratedConnection;

    /**
     * @var array<string, string> The data to attach to the span
     */
    private $spanData;

    /**
     * Constructor.
     *
     * @param HubInterface              $hub                 The current hub
     * @param DriverConnectionInterface $decoratedConnection The connection to decorate
     * @param string                    $databasePlatform    The name of the database platform
     * @param array<string, mixed>      $params              The connection params
     *
     * @phpstan-param ConnectionParams $params
     */
    public function __construct(
        HubInterface $hub,
        DriverConnectionInterface $decoratedConnection,
        string $databasePlatform,
        array $params
    ) {
        $this->hub = $hub;
        $this->decoratedConnection = $decoratedConnection;
        $this->spanData = $this->getSpanData($databasePlatform, $params);
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($sql): Statement
    {
        $statement = $this->traceFunction(self::SPAN_OP_CONN_PREPARE, $sql, function () use ($sql): Statement {
            return $this->decoratedConnection->prepare($sql);
        });

        return new TracingStatement($this->hub, $statement, $sql, $this->spanData);
    }

    /**
     * {@inheritdoc}
     */
    public function query(?string $sql = null, ...$args): Result
    {
        return $this->traceFunction(self::SPAN_OP_CONN_QUERY, $sql, function () use ($sql, $args): Result {
            return $this->decoratedConnection->query($sql, ...$args);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function quote(string $value): string
    {
        return $this->decoratedConnection->quote($value);
    }

    /**
     * {@inheritdoc}
     */
    public function exec(string $sql): int|string
    {
        return $this->traceFunction(self::SPAN_OP_CONN_EXEC, $sql, function () use ($sql): int|string {
            return $this->decoratedConnection->exec($sql);
        });
    }

    /**
     * {@inheritdoc}
     *
     * @return string|int
     */
    public function lastInsertId(): string|int
    {
        return $this->decoratedConnection->lastInsertId();
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction(): void
    {
        $this->traceFunction(self::SPAN_OP_CONN_BEGIN_TRANSACTION, 'BEGIN TRANSACTION', function (): void {
            $this->decoratedConnection->beginTransaction();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function commit(): void
    {
        $this->traceFunction(self::SPAN_OP_TRANSACTION_COMMIT, 'COMMIT', function (): void {
            $this->decoratedConnection->commit();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack(): void
    {
        $this->traceFunction(self::SPAN_OP_TRANSACTION_ROLLBACK, 'ROLLBACK', function (): void {
            $this->decoratedConnection->rollBack();
        });
    }

    /**
     * {@inheritdoc}
     *
     * @return resource|object
     */
    public function getNativeConnection()
    {
        return $this->decoratedConnection->getNativeConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function errorCode(): ?string
    {
        if (method_exists($this->decoratedConnection, 'errorCode')) {
            return $this->decoratedConnection->errorCode();
        }

        throw new \BadMethodCallException(sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
    }

    /**
     * {@inheritdoc}
     */
    public function errorInfo(): array
    {
        if (method_exists($this->decoratedConnection, 'errorInfo')) {
            return $this->decoratedConnection->errorInfo();
        }

        throw new \BadMethodCallException(sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
    }

    public function getWrappedConnection(): DriverConnectionInterface
    {
        return $this->decoratedConnection;
    }

    public function getServerVersion(): string
    {
        return $this->decoratedConnection->getServerVersion();
    }

    /**
     * @phpstan-template T
     *
     * @phpstan-param \Closure(): T $callback
     *
     * @phpstan-return T
     */
    private function traceFunction(string $spanOperation, string $spanDescription, \Closure $callback)
    {
        $span = $this->hub->getSpan();

        if (null !== $span) {
            $spanContext = new SpanContext();
            $spanContext->setOp($spanOperation);
            $spanContext->setDescription($spanDescription);
            $spanContext->setData($this->spanData);

            $span = $span->startChild($spanContext);
        }

        try {
            return $callback();
        } finally {
            if (null !== $span) {
                $span->finish();
            }
        }
    }

    /**
     * Gets a map of key-value pairs that will be set as the span data.
     *
     * @param array<string, mixed> $params The connection params
     *
     * @return array<string, string>
     *
     * @phpstan-param ConnectionParams $params
     *
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/semantic_conventions/database.md
     */
    private function getSpanData(string $databasePlatform, array $params): array
    {
        $data = ['db.system' => $databasePlatform];

        if (isset($params['user'])) {
            $data['db.user'] = $params['user'];
        }

        if (isset($params['dbname'])) {
            $data['db.name'] = $params['dbname'];
        }

        if (isset($params['host']) && !empty($params['host']) && !isset($params['memory'])) {
            if (false === filter_var($params['host'], \FILTER_VALIDATE_IP)) {
                $data['server.address'] = $params['host'];
            } else {
                $data['server.address'] = $params['host'];
            }
        }

        if (isset($params['port'])) {
            $data['server.port'] = (string) $params['port'];
        }

        if (isset($params['unix_socket'])) {
            $data['server.socket.address'] = 'Unix';
        } elseif (isset($params['memory'])) {
            $data['server.socket.address'] = 'inproc';
        }

        return $data;
    }
}
