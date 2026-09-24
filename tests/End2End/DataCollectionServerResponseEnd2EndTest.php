<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

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
final class DataCollectionServerResponseEnd2EndTest extends WebTestCase
{
    private const EXPECTED_RESPONSE_DATA = [
        'http.response.header.content-type' => ['application/json'],
        'http.response.header.x-auth-token' => ['[Filtered]'],
        'http.response.header.set_cookie.session_id' => '[Filtered]',
        'http.response.header.set_cookie.theme' => 'dark',
        'http.response.body.data' => ['username' => 'jane', 'password' => '[Filtered]'],
    ];

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

    public function testTransactionContainsResponseData(): void
    {
        $client = static::createClient(['extra_config_files' => [__DIR__ . '/App/config/data_collection/defaults.yml']]);

        $client->request('GET', '/response-data');

        $this->assertResponseIsOk($client->getResponse());
        $this->assertResponseData(self::EXPECTED_RESPONSE_DATA, $this->getTransactionData());
    }

    public function testTransactionDoesNotContainResponseDataWithLegacyOptions(): void
    {
        $client = static::createClient();

        $client->request('GET', '/response-data');

        $this->assertResponseIsOk($client->getResponse());
        $this->assertNoResponseData($this->getTransactionData());
    }

    public function testTransactionContainsFilteredBodyOfFileResponse(): void
    {
        $client = static::createClient(['extra_config_files' => [__DIR__ . '/App/config/data_collection/defaults.yml']]);

        $client->request('GET', '/response-data/file');

        $this->assertResponseIsOk($client->getResponse());
        $this->assertResponseData(['http.response.body.data' => '[Filtered]'], $this->getTransactionData());
    }

    public function testSubRequestSpanContainsResponseData(): void
    {
        $client = static::createClient(['extra_config_files' => [__DIR__ . '/App/config/data_collection/defaults.yml']]);

        $client->request('GET', '/response-data/subrequest');

        $this->assertResponseIsOk($client->getResponse());

        $subRequestSpans = array_values(array_filter($this->getTransactionEvent()->getSpans(), static function (Span $span): bool {
            return 'http.server' === $span->getOp();
        }));

        $this->assertCount(1, $subRequestSpans);
        $this->assertResponseData(self::EXPECTED_RESPONSE_DATA, $subRequestSpans[0]->getData());
        $this->assertResponseData(self::EXPECTED_RESPONSE_DATA, $this->getTransactionData());
    }

    /**
     * @param mixed $response
     */
    private function assertResponseIsOk($response): void
    {
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Only the expected keys are compared, as for example the date header
     * changes with every response.
     *
     * @param array<string, mixed> $expectedData
     * @param array<string, mixed> $data
     */
    private function assertResponseData(array $expectedData, array $data): void
    {
        $this->assertEquals($expectedData, array_intersect_key($data, $expectedData));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertNoResponseData(array $data): void
    {
        $this->assertSame([], preg_grep('/^http\.response\./', array_keys($data)));
    }

    /**
     * @return array<string, mixed>
     */
    private function getTransactionData(): array
    {
        /** @var array<string, mixed> $transactionData */
        $transactionData = $this->getTransactionEvent()->getContexts()['trace']['data'] ?? [];

        return $transactionData;
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
