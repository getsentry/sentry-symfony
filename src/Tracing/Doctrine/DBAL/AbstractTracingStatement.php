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
     * @var DataCollectionPolicy
     */
    private $dataCollectionPolicy;

    /**
     * @var array<string, array{param: int|string, value: mixed}>
     */
    private $bindings = [];

    /**
     * Constructor.
     *
     * @param HubInterface $hub The current hub
     * @param Statement $decoratedStatement The decorated statement
     * @param string $sqlQuery The SQL query executed by the decorated statement
     * @param array<string, string> $spanData The span data
     */
    public function __construct(HubInterface $hub, Statement $decoratedStatement, string $sqlQuery, array $spanData)
    {
        $this->hub = $hub;
        $this->dataCollectionPolicy = DataCollectionPolicy::fromHub($hub);
        $this->decoratedStatement = $decoratedStatement;
        $this->sqlQuery = $sqlQuery;
        $this->spanData = $spanData;
    }

    /**
     * Records a successful by-value binding as a detached snapshot.
     *
     * @param mixed $param
     * @param mixed $value
     */
    protected function recordBoundValue($param, $value): void
    {
        if (!$this->dataCollectionPolicy->shouldCollectDatabaseQueryData() || (!\is_int($param) && !\is_string($param))) {
            return;
        }

        $normalized = DatabaseDataCollector::normalizeQueryBindings([$param => $value]);
        $key = self::bindingKey($param);
        unset($this->bindings[$key]);
        $this->bindings[$key] = ['param' => $param, 'value' => $normalized[$param]];
    }

    /**
     * Records a successful by-reference binding.
     *
     * @param mixed $param
     * @param mixed $value
     */
    protected function recordBoundReference($param, &$value): void
    {
        if (!$this->dataCollectionPolicy->shouldCollectDatabaseQueryData() || (!\is_int($param) && !\is_string($param))) {
            return;
        }

        $key = self::bindingKey($param);
        unset($this->bindings[$key]);
        $this->bindings[$key] = ['param' => $param, 'value' => &$value];
    }

    /**
     * Normalize keys so that :foo and foo refer to the same param.
     *
     * @param int|string $param
     */
    private static function bindingKey($param): string
    {
        return \is_int($param)
            ? 'position:' . $param
            : 'name:' . (':' === substr($param, 0, 1) ? substr($param, 1) : $param);
    }

    /**
     * Gets the bindings that describe one execution without changing driver arguments.
     * Explicit parameters, including an empty array, take precedence over bound values.
     *
     * @param mixed $params
     *
     * @return array<array-key, mixed>
     */
    protected function getExecutionBindings($params = null): array
    {
        if (!$this->dataCollectionPolicy->shouldCollectDatabaseQueryData()) {
            return [];
        }

        if (\is_array($params)) {
            return $params;
        }

        $bindings = [];
        foreach ($this->bindings as $binding) {
            $bindings[$binding['param']] = $binding['value'];
        }

        return $bindings;
    }

    /**
     * Calls the given callback by passing to it the specified arguments and
     * wrapping its execution into a child {@see Span} of the current one.
     *
     * @param callable $callback The function to call
     * @param mixed ...$args The arguments to pass to the callback
     *
     * @phpstan-template T
     *
     * @phpstan-param callable(mixed...): T $callback
     *
     * @phpstan-return T
     */
    protected function traceFunction(SpanContext $spanContext, callable $callback, ...$args)
    {
        $bindings = $this->getExecutionBindings($args[0] ?? null);
        $span = $this->hub->getSpan();

        if (null !== $span) {
            $span = $span->startChild($spanContext);
        }

        try {
            if (null !== $span && true === $span->getSampled()) {
                $this->addQueryDataToSpan($span, $bindings);
            }

            return $callback(...$args);
        } finally {
            if (\is_array($args[0] ?? null)) {
                $this->bindings = [];
            }
            if (null !== $span) {
                $span->finish();
            }
        }
    }

    /**
     * @param array<array-key, mixed> $bindings
     */
    private function addQueryDataToSpan(Span $span, array $bindings): void
    {
        $data = DatabaseDataCollector::collectQueryData($this->dataCollectionPolicy, $bindings);
        $span->setData(array_diff_key($data, $span->getData()));
    }
}
