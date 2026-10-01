<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Statement;
use Sentry\DataCollection\DatabaseDataCollector;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

abstract class AbstractTracingStatement
{
    /**
     * @internal
     */
    public const SPAN_OP_STMT_EXECUTE = 'db.sql.execute';

    /**
     * @var HubInterface The current hub
     */
    protected $hub;

    /**
     * @var Statement The decorated statement
     */
    protected $decoratedStatement;

    /**
     * @var string The SQL query executed by the decorated statement
     */
    protected $sqlQuery;

    /**
     * @var array<string, string> The span data
     */
    protected $spanData;

    /**
     * @var array<array-key, mixed> The parameters bound to the decorated statement
     */
    private $parameters = [];

    /**
     * Constructor.
     *
     * @param HubInterface          $hub                The current hub
     * @param Statement             $decoratedStatement The decorated statement
     * @param string                $sqlQuery           The SQL query executed by the decorated statement
     * @param array<string, string> $spanData           The span data
     */
    public function __construct(HubInterface $hub, Statement $decoratedStatement, string $sqlQuery, array $spanData)
    {
        $this->hub = $hub;
        $this->decoratedStatement = $decoratedStatement;
        $this->sqlQuery = $sqlQuery;
        $this->spanData = $spanData;
    }

    /**
     * Calls the given callback by passing to it the specified arguments and
     * wrapping its execution into a child {@see Span} of the current one.
     *
     * @param callable $callback The function to call
     * @param mixed    ...$args  The arguments to pass to the callback
     *
     * @phpstan-template T
     *
     * @phpstan-param callable(mixed...): T $callback
     *
     * @phpstan-return T
     */
    protected function traceFunction(SpanContext $spanContext, callable $callback, ...$args)
    {
        $span = $this->hub->getSpan();

        if (null !== $span) {
            $span = $span->startChild($spanContext);
            $this->addQueryData($span, $args[0] ?? null);
        }

        try {
            return $callback(...$args);
        } finally {
            if (null !== $span) {
                $span->finish();
            }
        }
    }

    /**
     * @param int|string $param The name or 1-indexed position of the parameter
     * @param mixed      $value
     */
    protected function recordBoundValue($param, $value): void
    {
        $key = self::getParameterKey($param);

        // Unset the parameter first to not write through a parameter that was bound by reference
        unset($this->parameters[$key]);
        $this->parameters[$key] = $value;
    }

    /**
     * @param int|string $param    The name or 1-indexed position of the parameter
     * @param mixed      $variable
     */
    protected function recordBoundReference($param, &$variable): void
    {
        $this->parameters[self::getParameterKey($param)] = &$variable;
    }

    /**
     * @param mixed $params The parameters passed to the execution, which replace the bound ones
     */
    private function addQueryData(Span $span, $params): void
    {
        if (!$span->getSampled()) {
            return;
        }

        $data = DatabaseDataCollector::collectQueryData(
            DataCollectionPolicy::fromHub($this->hub),
            \is_array($params) && [] !== $params ? $params : $this->parameters
        );

        if ([] !== $data) {
            $span->setData($data);
        }
    }

    /**
     * Positional parameters are bound 1-indexed, but reported 0-indexed like
     * the parameters passed to the execution.
     *
     * @param int|string $param
     *
     * @return int|string
     */
    private static function getParameterKey($param)
    {
        return \is_int($param) ? $param - 1 : $param;
    }
}
