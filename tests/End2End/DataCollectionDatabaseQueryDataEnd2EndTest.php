<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Doctrine\DBAL\Connection;
use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;
use Sentry\Tracing\Span;
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
final class DataCollectionDatabaseQueryDataEnd2EndTest extends WebTestCase
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
    }

    /**
     * @param list<string>         $extraConfigFiles
     * @param array<string, mixed> $expectedQueryData
     *
     * @dataProvider queryDataDataProvider
     */
    public function testQueryParametersRespectDatabaseQueryDataOption(array $extraConfigFiles, array $expectedQueryData): void
    {
        if (!class_exists(Connection::class)) {
            $this->markTestSkipped('This test requires the "doctrine/dbal" Composer package to be installed.');
        }

        $client = static::createClient(['extra_config_files' => $extraConfigFiles]);

        $client->request('GET', '/tracing/ping-prepared-database');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $executeSpans = array_values(array_filter($this->getTransactionEvent()->getSpans(), static function (Span $span): bool {
            return 'db.sql.execute' === $span->getOp();
        }));

        $this->assertCount(1, $executeSpans);

        $queryData = array_filter($executeSpans[0]->getData(), static function (string $key): bool {
            return str_starts_with($key, 'db.query.parameter.');
        }, \ARRAY_FILTER_USE_KEY);

        $this->assertSame($expectedQueryData, $queryData);
    }

    public function queryDataDataProvider(): \Generator
    {
        yield 'The legacy options do not collect query parameters' => [
            [],
            [],
        ];

        yield 'The data collection options collect query parameters by default' => [
            [__DIR__ . '/App/config/data_collection/defaults.yml'],
            ['db.query.parameter.0' => 1],
        ];

        yield 'The data collection options do not collect query parameters if disabled' => [
            [__DIR__ . '/App/config/data_collection/database_query_data_disabled.yml'],
            [],
        ];
    }

    private function getTransactionEvent(): Event
    {
        $transactionEvents = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
            return null !== $event->getTransaction();
        }));

        $this->assertCount(1, $transactionEvents);

        return $transactionEvents[0];
    }
}
