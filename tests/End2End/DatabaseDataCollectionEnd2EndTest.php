<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithDatabaseDataCollection;
use Sentry\Tracing\Span;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

if (!class_exists(KernelBrowser::class) && class_exists(Client::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
final class DatabaseDataCollectionEnd2EndTest extends TestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    /**
     * @dataProvider enabledOptionsProvider
     *
     * @param array<string, mixed> $options
     */
    public function testEnabledCollectionCapturesPositionalBinding(array $options): void
    {
        $event = $this->request('/tracing/database-data-collection/positional', $options);
        $span = $this->singleExecutionSpan($event);

        $this->assertExecutionSpan($span, 'SELECT ?');
        $this->assertSame(['db.query.parameter.1' => 11], $this->queryParameterData($span));
    }

    public function enabledOptionsProvider(): \Generator
    {
        yield 'defaults without PII' => [['send_default_pii' => false, 'data_collection' => []]];
        yield 'defaults with PII' => [['send_default_pii' => true, 'data_collection' => []]];
        yield 'explicit true without PII' => [['send_default_pii' => false, 'data_collection' => ['database_query_data' => true]]];
        yield 'explicit true with PII' => [['send_default_pii' => true, 'data_collection' => ['database_query_data' => true]]];
    }

    /**
     * @dataProvider sendDefaultPiiProvider
     */
    public function testSensitiveNamedBindingIsFiltered(bool $sendDefaultPii): void
    {
        $event = $this->request('/tracing/database-data-collection/sensitive-named', [
            'send_default_pii' => $sendDefaultPii,
            'data_collection' => [],
        ]);
        $span = $this->singleExecutionSpan($event);

        $this->assertExecutionSpan($span, 'SELECT :password');
        $this->assertSame(['db.query.parameter.password' => '[Filtered]'], $this->queryParameterData($span));
    }

    public function sendDefaultPiiProvider(): \Generator
    {
        yield 'without PII' => [false];
        yield 'with PII' => [true];
    }

    public function testReusedStatementCapturesCurrentBinding(): void
    {
        $event = $this->request('/tracing/database-data-collection/reused-statement', ['data_collection' => []]);
        $spans = $this->databaseSpans($event, 'db.sql.execute');

        $this->assertCount(2, $spans);
        $this->assertExecutionSpan($spans[0], 'SELECT :name');
        $this->assertExecutionSpan($spans[1], 'SELECT :name');
        $this->assertSame(['db.query.parameter.name' => 'Alice'], $this->queryParameterData($spans[0]));
        $this->assertSame(['db.query.parameter.name' => 'Bob'], $this->queryParameterData($spans[1]));
    }

    public function testReturnedRowsAreNotCapturedAsQueryParameters(): void
    {
        $event = $this->request('/tracing/database-data-collection/returned-rows', ['data_collection' => []]);
        $span = $this->singleExecutionSpan($event);

        $this->assertExecutionSpan($span, "SELECT upper('returned_rows_only_marker_83')");
        $this->assertSame([], $this->queryParameterData($span));
        foreach ($event->getSpans() as $eventSpan) {
            $this->assertStringNotContainsString('RETURNED_ROWS_ONLY_MARKER_83', serialize($eventSpan->getData()));
        }
    }

    public function testPrepareSpanDoesNotCaptureQueryParameters(): void
    {
        $event = $this->request('/tracing/database-data-collection/positional', ['data_collection' => []]);
        $spans = $this->databaseSpans($event, 'db.sql.prepare');

        $this->assertCount(1, $spans);
        $this->assertSame([], $this->queryParameterData($spans[0]));
    }

    public function testOtherDatabaseOperationsDoNotCaptureQueryParameters(): void
    {
        $event = $this->request('/tracing/database-data-collection/other-operations', ['data_collection' => []]);
        $operations = [];

        $this->assertSame([], $this->databaseSpans($event, 'db.sql.execute'));
        foreach ($event->getSpans() as $span) {
            $operation = $span->getOp();
            if (null === $operation || !str_starts_with($operation, 'db.sql.')) {
                continue;
            }

            $operations[$operation] = true;
            $this->assertSame([], $this->queryParameterData($span));
            $this->assertNotNull($span->getEndTimestamp());
        }

        foreach (['db.sql.query', 'db.sql.exec', 'db.sql.transaction.begin', 'db.sql.transaction.commit', 'db.sql.transaction.rollback'] as $operation) {
            $this->assertArrayHasKey($operation, $operations);
        }
    }

    /**
     * @dataProvider disabledOptionsProvider
     *
     * @param array<string, mixed> $options
     */
    public function testDisabledAndLegacyModesKeepTracingWithoutBindings(array $options): void
    {
        $event = $this->request('/tracing/database-data-collection/positional', $options);
        $span = $this->singleExecutionSpan($event);

        $this->assertExecutionSpan($span, 'SELECT ?');
        $this->assertSame([], $this->queryParameterData($span));
    }

    public function disabledOptionsProvider(): \Generator
    {
        foreach ([false, true] as $sendDefaultPii) {
            $pii = $sendDefaultPii ? 'with PII' : 'without PII';

            yield 'explicit false ' . $pii => [['send_default_pii' => $sendDefaultPii, 'data_collection' => ['database_query_data' => false]]];
            yield 'absent ' . $pii => [['send_default_pii' => $sendDefaultPii]];
            yield 'null ' . $pii => [['send_default_pii' => $sendDefaultPii, 'data_collection' => null]];
        }
    }

    public function testIgnoringPrepareSpansDoesNotSuppressExecutionBindings(): void
    {
        $event = $this->request(
            '/tracing/database-data-collection/positional',
            ['data_collection' => []],
            true
        );

        $this->assertSame([], $this->databaseSpans($event, 'db.sql.prepare'));
        $span = $this->singleExecutionSpan($event);
        $this->assertSame(['db.query.parameter.1' => 11], $this->queryParameterData($span));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function request(string $path, array $options, bool $ignorePrepareSpans = false): Event
    {
        $this->skipIfDatabaseIsMissing();
        $kernel = new KernelWithDatabaseDataCollection($options, $ignorePrepareSpans);
        $client = new KernelBrowser($kernel);

        try {
            $client->request('GET', $path);
            $response = $client->getResponse();
            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('Success', $response->getContent());

            $events = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return null !== $event->getTransaction();
            }));
            $this->assertCount(1, $events);

            return $events[0];
        } finally {
            $kernel->shutdown();
        }
    }

    private function singleExecutionSpan(Event $event): Span
    {
        $spans = $this->databaseSpans($event, 'db.sql.execute');
        $this->assertCount(1, $spans);

        return $spans[0];
    }

    private function assertExecutionSpan(Span $span, string $description): void
    {
        $this->assertSame('db.sql.execute', $span->getOp());
        $this->assertSame('auto.db', $span->getOrigin());
        $this->assertSame('sqlite', $span->getData()['db.system']);
        $this->assertSame($description, $span->getDescription());
        $this->assertNotNull($span->getEndTimestamp());
    }

    /**
     * @return Span[]
     */
    private function databaseSpans(Event $event, string $operation): array
    {
        return array_values(array_filter($event->getSpans(), static function (Span $span) use ($operation): bool {
            return $operation === $span->getOp();
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParameterData(Span $span): array
    {
        return array_filter($span->getData(), static function ($key): bool {
            return str_starts_with((string) $key, 'db.query.parameter.');
        }, \ARRAY_FILTER_USE_KEY);
    }

    private function skipIfDatabaseIsMissing(): void
    {
        if (!class_exists(DoctrineBundle::class) || !class_exists(Connection::class)) {
            $this->markTestSkipped('DoctrineBundle and Doctrine DBAL are required.');
        }

        if (!\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('PDO SQLite is required.');
        }
    }
}
