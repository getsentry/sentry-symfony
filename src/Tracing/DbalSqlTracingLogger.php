<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing;

use Doctrine\DBAL\Logging\SQLLogger;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;

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
        $transaction = $this->hub->getTransaction();

        if (!$transaction instanceof Transaction) {
            return;
        }

        $spanContext = new SpanContext();
        $spanContext->setOp('db.query');
        $spanContext->setDescription($sql);
        $spanContext->setData([
            'db.system' => 'doctrine',
        ]);

        $this->span = $transaction->startChild($spanContext);
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
    }
}
