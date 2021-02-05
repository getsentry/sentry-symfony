<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing;

use Doctrine\DBAL\Logging\SQLLogger;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;

/**
 * Simple SQL logger that records a trace of each query and sends it to Sentry.
 */
final class DbalSqlTracingLogger implements SQLLogger
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var Span The span tracing the execution of a query
     */
    private $span;

    /**
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * {@inheritdoc}
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null)
    {
        $span = $this->hub->getSpan();

        if (null === $span) {
            return;
        }

        $spanContext = new SpanContext();
        $spanContext->setOp('db.query');
        $spanContext->setDescription($sql);

        $this->span = $span->startChild($spanContext);
    }

    /**
     * {@inheritdoc}
     */
    public function stopQuery()
    {
        if (null === $this->span) {
            return;
        }

        $this->span->finish();
        $this->span = null;
    }
}
