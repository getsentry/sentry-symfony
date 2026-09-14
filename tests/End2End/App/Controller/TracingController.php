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
        $this->hub->setSpan(
            $this->hub->getSpan()
                ->startChild($this->createSpan())
        );

        if ($this->connection) {
            $this->connection->executeQuery('SELECT 1');
        }

        return new Response('Success');
    }

    public function pingPreparedDatabase(): Response
    {
        $this->hub->setSpan(
            $this->hub->getSpan()
                ->startChild($this->createSpan())
        );

        if ($this->connection) {
            $this->connection->executeQuery('SELECT ?', [1]);
        }

        return new Response('Success');
    }

    public function databaseDataCollectionPositional(): Response
    {
        if (!$this->connection) {
            return new Response('Success');
        }

        $statement = $this->connection->prepare('SELECT ?');
        $statement->bindValue(1, 11);
        if (11 !== (int) $this->executeAndFetchOne($statement)) {
            throw new \RuntimeException('Unexpected positional query result.');
        }

        return new Response('Success');
    }

    public function databaseDataCollectionSensitiveNamed(): Response
    {
        if (!$this->connection) {
            return new Response('Success');
        }

        $statement = $this->connection->prepare('SELECT :password');
        $statement->bindValue('password', 'sensitive binding value');
        if ('sensitive binding value' !== $this->executeAndFetchOne($statement)) {
            throw new \RuntimeException('Unexpected named query result.');
        }

        return new Response('Success');
    }

    public function databaseDataCollectionReusedStatement(): Response
    {
        if (!$this->connection) {
            return new Response('Success');
        }

        $statement = $this->connection->prepare('SELECT :name');
        $statement->bindValue(':name', 'replaced alias');
        $statement->bindValue('name', 'Alice');
        if ('Alice' !== $this->executeAndFetchOne($statement)) {
            throw new \RuntimeException('Unexpected first reused query result.');
        }
        $statement->bindValue('name', 'Bob');
        if ('Bob' !== $this->executeAndFetchOne($statement)) {
            throw new \RuntimeException('Unexpected second reused query result.');
        }

        return new Response('Success');
    }

    public function databaseDataCollectionReturnedRows(): Response
    {
        if (!$this->connection) {
            return new Response('Success');
        }

        $statement = $this->connection->prepare("SELECT upper('returned_rows_only_marker_83')");
        if ('RETURNED_ROWS_ONLY_MARKER_83' !== $this->executeAndFetchOne($statement)) {
            throw new \RuntimeException('Unexpected returned-only query result.');
        }

        return new Response('Success');
    }

    public function databaseDataCollectionOtherOperations(): Response
    {
        if (!$this->connection) {
            return new Response('Success');
        }

        $result = $this->connection->executeQuery("SELECT 'direct_query_result'");
        if ('direct_query_result' !== $result->fetchOne()) {
            throw new \RuntimeException('Unexpected direct query result.');
        }

        $this->connection->executeStatement('CREATE TEMPORARY TABLE sentry_data_collection (id INTEGER)');
        $this->connection->beginTransaction();
        $this->connection->commit();
        $this->connection->beginTransaction();
        $this->connection->rollBack();

        return new Response('Success');
    }

    public function ignoredTransaction(): Response
    {
        $this->hub->setSpan(
            $this->hub->getSpan()
                ->startChild($this->createSpan())
        );

        return new Response('Success');
    }

    /**
     * @param object $statement
     *
     * @return mixed
     */
    private function executeAndFetchOne($statement)
    {
        if (method_exists($statement, 'executeQuery')) {
            $result = $statement->executeQuery();

            return $result->fetchOne();
        }

        $statement->execute();

        return $statement->fetchColumn();
    }

    private function createSpan(): SpanContext
    {
        $spanContext = new SpanContext();
        $spanContext->setOp('mock.span');
        $spanContext->setDescription('mocked subspan');

        return $spanContext;
    }
}
