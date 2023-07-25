<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use Sentry\Tracing\SpanContext;

/**
 * @internal
 */
final class TracingStatementForV3 extends AbstractTracingStatement implements Statement
{
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
        return $this->decoratedStatement->bindParam($param, $variable, $type, ...\array_slice(\func_get_args(), 3));
    }

    /**
     * {@inheritdoc}
     */
    public function execute($params = null): Result
    {
        $spanContext = new SpanContext();
        $spanContext->setOp(self::SPAN_OP_STMT_EXECUTE);
        $spanContext->setDescription($this->sqlQuery);
        $spanContext->setData($this->spanData);

        return $this->traceFunction($spanContext, [$this->decoratedStatement, 'execute'], $params);
    }
}
