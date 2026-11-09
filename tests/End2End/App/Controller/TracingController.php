<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Doctrine\DBAL\Connection;
use Sentry\SentryBundle\SentryManager;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpFoundation\Response;

class TracingController
{
    /**
     * @var SentryManager
     */
    private $sentry;

    /**
     * @var Connection|null
     */
    private $connection;

    public function __construct(SentryManager $sentry, ?Connection $connection = null)
    {
        $this->sentry = $sentry;
        $this->connection = $connection;
    }

    public function pingDatabase(): Response
    {
        $this->sentry->setSpan(
            $this->sentry->getSpan()
                ->startChild($this->createSpan())
        );

        if ($this->connection) {
            $this->connection->executeQuery('SELECT 1');
        }

        return new Response('Success');
    }

    public function pingPreparedDatabase(): Response
    {
        $this->sentry->setSpan(
            $this->sentry->getSpan()
                ->startChild($this->createSpan())
        );

        if ($this->connection) {
            $this->connection->executeQuery('SELECT ?', [1]);
        }

        return new Response('Success');
    }

    public function ignoredTransaction(): Response
    {
        $this->sentry->setSpan(
            $this->sentry->getSpan()
                ->startChild($this->createSpan())
        );

        return new Response('Success');
    }

    public function notFound(): Response
    {
        return new Response('Not Found', 404);
    }

    public function statusCodeInIgnoredRange(): Response
    {
        return new Response('Status Code In Ignored Range', 599);
    }

    private function createSpan(): SpanContext
    {
        $spanContext = new SpanContext();
        $spanContext->setOp('mock.span');
        $spanContext->setDescription('mocked subspan');

        return $spanContext;
    }
}
