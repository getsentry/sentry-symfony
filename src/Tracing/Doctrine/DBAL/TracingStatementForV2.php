<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Sentry\Tracing\SpanContext;

/**
 * @internal
 *
 * @phpstan-implements \IteratorAggregate<mixed>
 */
final class TracingStatementForV2 extends AbstractTracingStatement implements \IteratorAggregate, Statement
{
    /**
     * {@inheritdoc}
     */
    public function getIterator(): \Traversable
    {
        return $this->decoratedStatement;
    }

    /**
     * {@inheritdoc}
     */
    public function closeCursor(): bool
    {
        return $this->decoratedStatement->closeCursor();
    }

    /**
     * {@inheritdoc}
     */
    public function columnCount(): int
    {
        return $this->decoratedStatement->columnCount();
    }

    /**
     * {@inheritdoc}
     */
    public function setFetchMode($fetchMode, $arg2 = null, $arg3 = null): bool
    {
        return $this->decoratedStatement->setFetchMode($fetchMode, $arg2, $arg3);
    }

    /**
     * {@inheritdoc}
     */
    public function fetch($fetchMode = null, $cursorOrientation = \PDO::FETCH_ORI_NEXT, $cursorOffset = 0)
    {
        return $this->decoratedStatement->fetch($fetchMode, $cursorOrientation, $cursorOffset);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchAll($fetchMode = null, $fetchArgument = null, $ctorArgs = null)
    {
        return $this->decoratedStatement->fetchAll($fetchMode, $fetchArgument, $ctorArgs);
    }

    /**
     * {@inheritdoc}
     */
    public function fetchColumn($columnIndex = 0)
    {
        return $this->decoratedStatement->fetchColumn($columnIndex);
    }

    /**
     * {@inheritdoc}
     */
    public function errorCode()
    {
        return $this->decoratedStatement->errorCode();
    }

    /**
     * {@inheritdoc}
     */
    public function errorInfo(): array
    {
        return $this->decoratedStatement->errorInfo();
    }

    /**
     * {@inheritdoc}
     */
    public function rowCount(): int
    {
        return $this->decoratedStatement->rowCount();
    }

    /**
     * {@inheritdoc}
     */
    public function bindValue($param, $value, $type = ParameterType::STRING): bool
    {
        return $this->decoratedStatement->bindValue($param, $value, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function bindParam($param, &$variable, $type = ParameterType::STRING, $length = null): bool
    {
        return $this->decoratedStatement->bindParam($param, $variable, $type, $length);
    }

    /**
     * {@inheritdoc}
     */
    public function execute($params = null): bool
    {
        $spanContext = new SpanContext();
        $spanContext->setOp(self::SPAN_OP_STMT_EXECUTE);
        $spanContext->setDescription($this->sqlQuery);
        $spanContext->setTags($this->spanTags);

        return $this->traceFunction($spanContext, [$this->decoratedStatement, 'execute'], $params);
    }
}
