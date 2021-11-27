<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Statement;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

abstract class AbstractTracingStatement
{
    /**
     * @internal
     */
    public const SPAN_OP_STMT_EXECUTE = 'sql.stmt.execute';

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
     * @var array<string, string> The span tags
     */
    protected $spanTags;

    /**
     * Constructor.
     *
     * @param HubInterface          $hub                The current hub
     * @param Statement             $decoratedStatement The decorated statement
     * @param string                $sqlQuery           The SQL query executed by the decorated statement
     * @param array<string, string> $spanTags           The span tags
     */
    public function __construct(HubInterface $hub, Statement $decoratedStatement, string $sqlQuery, array $spanTags)
    {
        $this->hub = $hub;
        $this->decoratedStatement = $decoratedStatement;
        $this->sqlQuery = $sqlQuery;
        $this->spanTags = $spanTags;
    }

    /**
     * Calls the given callback by passing to it the specified arguments and
     * wrapping its execution into a child {@see Span} of the current one.
     *
     * @param \Closure $callback The function to call
     *
     * @phpstan-template T
     *
     * @phpstan-param \Closure(mixed...): T $callback
     *
     * @phpstan-return T
     */
    protected function traceFunction(SpanContext $spanContext, \Closure $callback)
    {
        $span = $this->hub->getSpan();

        if (null !== $span) {
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
}
