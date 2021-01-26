<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener\Tracing;

use Doctrine\DBAL\Logging\SQLLogger;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;

/**
 * Getting the logger, tied into dbal seems extremely hard. Cheating the system a bit by putting it in between the
 * debug stack logger.
 */
final class DbalListener implements SQLLogger
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var Span
     */
    private $querySpan;

    /**
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * @param string $sql
     */
    public function startQuery($sql, ?array $params = null, ?array $types = null)
    {
        $transaction = $this->hub->getTransaction();

        if (!$transaction instanceof Transaction) {
            return;
        }

        $spanContext = new SpanContext();
        $spanContext->setOp('doctrine.query');
        $spanContext->setDescription($sql);

        $this->querySpan = $transaction->startChild($spanContext);
    }

    public function stopQuery()
    {
        if (!$this->querySpan instanceof Span) {
            return;
        }

        $this->querySpan->finish();
    }
}
