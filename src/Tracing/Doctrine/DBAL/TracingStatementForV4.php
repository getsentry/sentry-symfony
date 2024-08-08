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
final class TracingStatementForV4 extends AbstractTracingStatement implements Statement
{
    /**
     * {@inheritdoc}
     */
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        $this->decoratedStatement->bindValue($param, $value, $type);
    }

    /**
     * {@inheritdoc}
     */
    public function execute(): Result
    {
        $spanContext = SpanContext::make()
            ->setOp(self::SPAN_OP_STMT_EXECUTE)
            ->setData($this->spanData)
            ->setOrigin('auto.db')
            ->setDescription($this->sqlQuery);

        return $this->traceFunction($spanContext, [$this->decoratedStatement, 'execute']);
    }
}
