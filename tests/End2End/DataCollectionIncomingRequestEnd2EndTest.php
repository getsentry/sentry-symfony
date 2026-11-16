<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Event;
use Sentry\EventType;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

if (!class_exists(KernelBrowser::class) && class_exists(Client::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
final class DataCollectionIncomingRequestEnd2EndTest extends WebTestCase
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
     * @param list<string> $extraConfigFiles
     *
     * @dataProvider transactionUrlDataProvider
     */
    public function testTransactionUrlRespectsUrlQueryParamsOption(array $extraConfigFiles, string $expectedUrl): void
    {
        $client = static::createClient(['extra_config_files' => $extraConfigFiles]);

        $client->request('GET', '/200?token=secret&q=a%20b%26c&page=5');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $transactionData */
        $transactionData = $this->getTransactionEvent()->getContexts()['trace']['data'] ?? [];

        $this->assertSame($expectedUrl, $transactionData['http.url'] ?? null);
    }

    public function transactionUrlDataProvider(): \Generator
    {
        yield 'The data collection options keep the query string as received and filter sensitive values' => [
            [__DIR__ . '/App/config/data_collection/defaults.yml'],
            'http://localhost/200?token=[Filtered]&q=a%20b%26c&page=5',
        ];

        yield 'A null value in a later config file restores the defaults' => [
            [__DIR__ . '/App/config/data_collection/url_query_params_disabled.yml', __DIR__ . '/App/config/data_collection/unset.yml'],
            'http://localhost/200?token=[Filtered]&q=a%20b%26c&page=5',
        ];

        yield 'The data collection options omit the query string if query parameters are not collected' => [
            [__DIR__ . '/App/config/data_collection/url_query_params_disabled.yml'],
            'http://localhost/200',
        ];
    }

    public function testTransactionContainsRequestData(): void
    {
        $client = static::createClient(['extra_config_files' => [__DIR__ . '/App/config/data_collection/defaults.yml']]);
        $client->getCookieJar()->set(new Cookie('theme', 'dark'));
        $client->getCookieJar()->set(new Cookie('REMEMBERME', 'token'));

        $client->request('POST', '/200', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer secret',
            'HTTP_X_REQUEST_ID' => 'bar',
        ], '{"username":"jane","password":"secret"}');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $transactionData */
        $transactionData = $this->getTransactionEvent()->getContexts()['trace']['data'] ?? [];

        $this->assertSame('bar', $transactionData['http.request.header.x-request-id'] ?? null);
        $this->assertSame('[Filtered]', $transactionData['http.request.header.authorization'] ?? null);
        $this->assertSame(['theme=dark', 'REMEMBERME=[Filtered]'], $transactionData['http.request.header.cookie'] ?? null);
        $this->assertSame('{"username":"jane","password":"[Filtered]"}', $transactionData['http.request.body.data'] ?? null);
    }

    private function getTransactionEvent(): Event
    {
        $transactionEvents = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
            // Message events have the name of the active transaction too, so only the type tells them apart
            return EventType::transaction() === $event->getType();
        }));

        $this->assertCount(1, $transactionEvents);

        return $transactionEvents[0];
    }
}
