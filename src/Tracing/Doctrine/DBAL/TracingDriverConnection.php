<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Connection as DriverConnectionInterface;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;

/**
 * This implementation wraps a driver connection and adds distributed tracing
 * capabilities to Doctrine DBAL.
 */
final class TracingDriverConnection implements DriverConnectionInterface
{
    /**
     * @internal
     */
    public const SPAN_OP_CONN_PREPARE = 'sql.conn.prepare';

    /**
     * @internal
     */
    public const SPAN_OP_CONN_QUERY = 'sql.conn.query';

    /**
     * @internal
     */
    public const SPAN_OP_CONN_EXEC = 'sql.conn.exec';

    /**
     * @internal
     */
    public const SPAN_OP_CONN_BEGIN_TRANSACTION = 'sql.conn.begin_transaction';

    /**
     * @internal
     */
    public const SPAN_OP_TRANSACTION_COMMIT = 'sql.begin_transaction.commit';

    /**
     * @internal
     */
    public const SPAN_OP_TRANSACTION_ROLLBACK = 'sql.begin_transaction.rollback';

    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var DriverConnectionInterface The decorated connection
     */
    private $decoratedConnection;

    /**
     * @var string The name of the database platform
     */
    private $databasePlatform;

    /**
     * @var array<string, mixed> The connection params
     */
    private $params;

    /**
     * Constructor.
     *
     * @param HubInterface              $hub                 The current hub
     * @param DriverConnectionInterface $decoratedConnection The connection to decorate
     * @param string                    $databasePlatform    The name of the database platform
     * @param array<string, mixed>      $params              The connection params
     */
    public function __construct(
        HubInterface $hub,
        DriverConnectionInterface $decoratedConnection,
        string $databasePlatform,
        array $params
    ) {
        $this->hub = $hub;
        $this->decoratedConnection = $decoratedConnection;
        $this->databasePlatform = $databasePlatform;
        $this->params = $params;
    }

    /**
     * {@inheritdoc}
     */
    public function prepare($sql): Statement
    {
        return $this->traceFunction(self::SPAN_OP_CONN_PREPARE, $sql, function () use ($sql): Statement {
            return $this->decoratedConnection->prepare($sql);
        });
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
    public function quote($value, $type = ParameterType::STRING)
    {
        return $this->decoratedConnection->quote($value, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function exec($sql): int
    {
        return $this->traceFunction(self::SPAN_OP_CONN_EXEC, $sql, function () use ($sql): int {
            return $this->decoratedConnection->exec($sql);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function lastInsertId($name = null)
    {
        return $this->decoratedConnection->lastInsertId($name);
    }

    /**
     * {@inheritdoc}
     */
    public function beginTransaction()
    {
        return $this->traceFunction(self::SPAN_OP_CONN_BEGIN_TRANSACTION, 'BEGIN TRANSACTION', function (): bool {
            return $this->decoratedConnection->beginTransaction();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function commit()
    {
        return $this->traceFunction(self::SPAN_OP_TRANSACTION_COMMIT, 'COMMIT', function (): bool {
            return $this->decoratedConnection->commit();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function rollBack()
    {
        return $this->traceFunction(self::SPAN_OP_TRANSACTION_ROLLBACK, 'ROLLBACK', function (): bool {
            return $this->decoratedConnection->rollBack();
        });
    }

    /**
     * {@inheritdoc}
     */
    public function errorCode()
    {
        if (method_exists($this->decoratedConnection, 'errorInfo')) {
            return $this->decoratedConnection->errorCode();
        }

        throw new \BadMethodCallException(sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
    }

    /**
     * {@inheritdoc}
     */
    public function errorInfo()
    {
        if (method_exists($this->decoratedConnection, 'errorInfo')) {
            return $this->decoratedConnection->errorInfo();
        }

        throw new \BadMethodCallException(sprintf('The %s() method is not supported on Doctrine DBAL 3.0.', __METHOD__));
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
            $spanContext->setTags($this->getSpanTags());

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
     * @see https://github.com/open-telemetry/opentelemetry-specification/blob/main/specification/trace/semantic_conventions/database.md
     *
     * @return array<string, string>
     */
    private function getSpanTags(): array
    {
        $tags = ['db.system' => $this->databasePlatform];

        if (isset($this->params['user'])) {
            $tags['db.user'] = $this->params['user'];
        }

        if (isset($this->params['dbname'])) {
            $tags['db.name'] = $this->params['dbname'];
        }

        if (isset($this->params['host']) && !empty($this->params['host']) && !isset($this->params['memory'])) {
            if (false === filter_var($this->params['host'], \FILTER_VALIDATE_IP)) {
                $tags['net.peer.name'] = $this->params['host'];
            } else {
                $tags['net.peer.ip'] = $this->params['host'];
            }
        }

        if (isset($this->params['port'])) {
            $tags['net.peer.port'] = (string) $this->params['port'];
        }

        if (isset($this->params['unix_socket'])) {
            $tags['net.transport'] = 'Unix';
        } elseif (isset($this->params['memory'])) {
            $tags['net.transport'] = 'inproc';
        }

        return $tags;
    }
}
