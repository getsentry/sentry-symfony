<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Doctrine\DBAL\Connection;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpFoundation\Response;

class TracingController
{
    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var Connection|null
     */
    private $connection;

    public function __construct(HubInterface $hub, ?Connection $connection = null)
    {
        $this->hub = $hub;
        $this->connection = $connection;
    }

    public function pingDatabase(): Response
    {
        try {
            $this->hub->setSpan(
                $this->hub->getSpan()
                    ->startChild($this->createSpan())
            );

            if ($this->connection) {
                $this->connection->executeQuery('SELECT 1');
            }

            return new Response('Success');
        } catch (\Throwable $e) {
            $errorMessage = sprintf(
                "Exception: %s\nMessage: %s\nFile: %s\nLine: %d",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            return new Response($errorMessage, 200);
        }
    }

    public function ignoredTransaction(): Response
    {
        try {
            $this->hub->setSpan(
                $this->hub->getSpan()
                    ->startChild($this->createSpan())
            );

            return new Response('Success');
        } catch (\Throwable $e) {
            $errorMessage = sprintf(
                "Exception: %s\nMessage: %s\nFile: %s\nLine: %d",
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            return new Response($errorMessage, 200);
        }
    }

    private function createSpan(): SpanContext
    {
        $spanContext = new SpanContext();
        $spanContext->setOp('mock.span');
        $spanContext->setDescription('mocked subspan');

        return $spanContext;
    }
}
