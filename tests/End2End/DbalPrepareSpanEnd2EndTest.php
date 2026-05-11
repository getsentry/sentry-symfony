<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Doctrine\DBAL\Connection;
use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

if (!class_exists(KernelBrowser::class) && class_exists(Client::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
final class DbalPrepareSpanEnd2EndTest extends WebTestCase
{
    /**
     * @param array{extra_config_files?: list<string>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new KernelWithExtraConfig(array_merge([
            __DIR__ . '/App/tracing.yml',
        ], $options['extra_config_files'] ?? []));
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubTransport::$events = [];

        file_put_contents(End2EndTest::SENT_EVENTS_LOG, '');
    }

    public function testPreparedQueriesEmitPrepareSpansByDefault(): void
    {
        $this->skipIfDoctrineIsMissing();

        $event = $this->requestPreparedQuery([]);

        $this->assertPreparedQuerySpans($event, true);
    }

    public function testPreparedQueriesCanIgnorePrepareSpans(): void
    {
        $this->skipIfDoctrineIsMissing();

        $event = $this->requestPreparedQuery([
            __DIR__ . '/App/config/ignore_prepare_spans_enabled.yml',
        ]);

        $this->assertPreparedQuerySpans($event, false);
    }

    /**
     * @param string[] $extraConfigFiles
     */
    private function requestPreparedQuery(array $extraConfigFiles): Event
    {
        $client = static::createClient([
            'extra_config_files' => $extraConfigFiles,
        ]);

        $client->request('GET', '/tracing/ping-prepared-database');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getContent());

        return $this->getTracingEvent();
    }

    private function getTracingEvent(): Event
    {
        $tracingEvents = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
            return null !== $event->getTransaction();
        }));

        $this->assertCount(1, $tracingEvents);

        return $tracingEvents[0];
    }

    private function assertPreparedQuerySpans(Event $event, bool $shouldContainPrepareSpan): void
    {
        $dbalSpans = array_values(array_filter($event->getSpans(), static function ($span): bool {
            return str_starts_with((string) $span->getOp(), 'db.sql.');
        }));
        $dbalSpanOps = array_map(static function ($span): string {
            return (string) $span->getOp();
        }, $dbalSpans);
        $expectedSpanOps = $shouldContainPrepareSpan
            ? ['db.sql.prepare', 'db.sql.execute']
            : ['db.sql.execute'];

        $this->assertSame($expectedSpanOps, $dbalSpanOps);
        $this->assertContains('db.sql.execute', $dbalSpanOps);
        $this->assertNotContains('db.sql.query', $dbalSpanOps);
        $this->assertSame('SELECT ?', $dbalSpans[\count($dbalSpans) - 1]->getDescription());
    }

    private function skipIfDoctrineIsMissing(): void
    {
        if (!class_exists(Connection::class)) {
            $this->markTestSkipped('Skipped if doctrine is not available');
        }
    }
}
