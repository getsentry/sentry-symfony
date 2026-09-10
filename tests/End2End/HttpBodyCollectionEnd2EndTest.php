<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithHttpHeaders;
use Sentry\Tracing\Span;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @runTestsInSeparateProcesses
 *
 * @phpstan-type BodyData array{'http.request.body.data'?: mixed, 'http.response.body.data'?: mixed}
 * @phpstan-type BodyExchange array{main: BodyData, subrequest: BodyData, eventRequestBody: mixed, requestContent: string, responseContent: string}
 */
final class HttpBodyCollectionEnd2EndTest extends TestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    /**
     * @dataProvider bodyFormatProvider
     *
     * @param mixed $expected
     */
    public function testConfiguredBodyFormats(string $content, string $contentType, $expected): void
    {
        $exchange = $this->request(['data_collection' => []], $content, $contentType);
        $expectedData = [
            'http.request.body.data' => $expected,
            'http.response.body.data' => $expected,
        ];

        $this->assertSame($expectedData, $exchange['main']);
        $this->assertSame($expectedData, $exchange['subrequest']);
    }

    public function bodyFormatProvider(): \Generator
    {
        $json = '{"profile":{"password":"secret","name":"Alice"}}';
        $filtered = ['profile' => ['password' => '[Filtered]', 'name' => 'Alice']];

        yield 'JSON' => [$json, 'application/json', $filtered];
        yield 'JSON suffix' => [$json, 'application/problem+json; charset=UTF-8', $filtered];
        yield 'form' => ['name=Alice&token=secret', 'application/x-www-form-urlencoded', ['name' => 'Alice', 'token' => '[Filtered]']];
        yield 'invalid JSON' => ['{invalid', 'application/json', '[Filtered]'];
        yield 'raw' => ['secret', 'text/plain', '[Filtered]'];
        yield 'empty object' => ['{}', 'application/json', []];
    }

    public function testEmptyBodiesAreNotAdded(): void
    {
        $exchange = $this->request(['data_collection' => []], '', 'application/json');

        $this->assertSame([], $exchange['main']);
        $this->assertSame([], $exchange['subrequest']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testConfiguredCollectionIgnoresLegacyPii(bool $sendDefaultPii): void
    {
        $content = '{"password":"secret"}';
        $exchange = $this->request(['data_collection' => [], 'send_default_pii' => $sendDefaultPii], $content, 'application/json');
        $expected = [
            'http.request.body.data' => ['password' => '[Filtered]'],
            'http.response.body.data' => ['password' => '[Filtered]'],
        ];

        $this->assertSame($expected, $exchange['main']);
        $this->assertSame($expected, $exchange['subrequest']);
    }

    /**
     * @dataProvider bodyDirectionProvider
     *
     * @param array<string, mixed> $dataCollection
     * @param array<string, mixed> $expected
     */
    public function testBodyDirectionsCanBeConfiguredIndependently(array $dataCollection, array $expected): void
    {
        $content = '{"password":"secret"}';
        $exchange = $this->request(['data_collection' => $dataCollection], $content, 'application/json');

        $this->assertSame($expected, $exchange['main']);
        $this->assertSame($expected, $exchange['subrequest']);
    }

    public function bodyDirectionProvider(): \Generator
    {
        $filtered = ['password' => '[Filtered]'];

        yield 'request only' => [['http_bodies' => ['incomingRequest']], ['http.request.body.data' => $filtered]];
        yield 'response only' => [['http_bodies' => ['outgoingResponse']], ['http.response.body.data' => $filtered]];
        yield 'disabled' => [['http_bodies' => []], []];
    }

    public function testDisabledBodyCollectionOverridesLegacyPii(): void
    {
        $options = ['data_collection' => ['http_bodies' => []], 'send_default_pii' => true];
        $exchange = $this->request($options, '{"password":"secret"}', 'application/json');

        $this->assertSame([], $exchange['main']);
        $this->assertSame([], $exchange['subrequest']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testLegacyModeDoesNotAddBodiesToSpans(bool $sendDefaultPii): void
    {
        $content = '{"password":"secret"}';
        $exchange = $this->request(['send_default_pii' => $sendDefaultPii], $content, 'application/json');

        $this->assertSame([], $exchange['main']);
        $this->assertSame([], $exchange['subrequest']);
        $this->assertSame(['password' => 'secret'], $exchange['eventRequestBody']);
    }

    public function legacyPiiProvider(): \Generator
    {
        yield 'PII disabled' => [false];
        yield 'PII enabled' => [true];
    }

    /**
     * @dataProvider requestLimitProvider
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $expected
     */
    public function testRequestBodyLimitsDoNotDisableResponseBodies(array $options, string $content, array $expected): void
    {
        $exchange = $this->request($options + ['data_collection' => []], $content, 'text/plain');

        $this->assertSame($expected, $exchange['main']);
        $this->assertSame($expected, $exchange['subrequest']);
    }

    public function requestLimitProvider(): \Generator
    {
        yield 'request collection disabled' => [
            ['max_request_body_size' => 'never'],
            'secret',
            ['http.response.body.data' => '[Filtered]'],
        ];
        yield 'request too large' => [
            ['max_request_body_size' => 'small'],
            str_repeat('x', 1001),
            ['http.response.body.data' => '[Filtered]'],
        ];
    }

    public function testOversizedResponseBodyIsNotAdded(): void
    {
        $exchange = $this->request(['data_collection' => []], str_repeat('x', 100001), 'text/plain');

        $this->assertSame([], $exchange['main']);
        $this->assertSame([], $exchange['subrequest']);
    }

    /**
     * @dataProvider explicitBodyConfigurationProvider
     *
     * @param array<string, mixed> $dataCollection
     */
    public function testExplicitBodyDataIsPreserved(array $dataCollection): void
    {
        $exchange = $this->request(['data_collection' => $dataCollection], '{"password":"secret"}', 'application/json', '/body-collection?explicit=1');
        $expected = ['http.request.body.data' => [], 'http.response.body.data' => []];

        $this->assertSame($expected, $exchange['main']);
        $this->assertSame($expected, $exchange['subrequest']);
    }

    public function explicitBodyConfigurationProvider(): \Generator
    {
        yield 'collection enabled' => [[]];
        yield 'collection disabled' => [['http_bodies' => []]];
    }

    public function testCollectionDoesNotConsumeRequestOrResponseContent(): void
    {
        $content = '{"name":"Alice"}';
        $exchange = $this->request(['data_collection' => []], $content, 'application/json');

        $this->assertSame($content, $exchange['requestContent']);
        $this->assertSame($content, $exchange['responseContent']);
    }

    public function testEventBodyWithoutTracing(): void
    {
        $kernel = new KernelWithHttpHeaders(['data_collection' => [], 'max_request_body_size' => 'always', 'traces_sample_rate' => 0.0]);
        $client = new KernelBrowser($kernel);
        try {
            $body = '{"name":"Alice","password":"secret"}';
            $client->request('POST', '/200', [], [], ['CONTENT_TYPE' => 'application/json'], $body);
            $events = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return null === $event->getTransaction();
            }));

            $this->assertCount(1, $events);
            $this->assertSame(['name' => 'Alice', 'password' => '[Filtered]'], $events[0]->getRequest()['data']);
            $this->assertSame($body, $client->getRequest()->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @param array<string, mixed> $options
     *
     * @phpstan-return BodyExchange
     */
    private function request(array $options, string $content, string $contentType, string $path = '/body-collection'): array
    {
        $kernel = new KernelWithHttpHeaders($options + ['max_request_body_size' => 'always']);
        $client = new KernelBrowser($kernel);

        try {
            $client->request('POST', $path, [], [], [
                'CONTENT_TYPE' => $contentType,
                'CONTENT_LENGTH' => (string) \strlen($content),
            ], $content);
            $transactions = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return null !== $event->getTransaction();
            }));
            $this->assertCount(1, $transactions);
            $subspans = array_values(array_filter($transactions[0]->getSpans(), static function (Span $span): bool {
                return 'http.server' === $span->getOp();
            }));
            $this->assertCount(1, $subspans);
            $eventRequest = $transactions[0]->getRequest();
            $transactionData = $transactions[0]->getContexts()['trace']['data'];
            $this->assertIsArray($transactionData);

            return [
                'main' => $this->bodyData($transactionData),
                'subrequest' => $this->bodyData($subspans[0]->getData()),
                'eventRequestBody' => $eventRequest['data'] ?? null,
                'requestContent' => $client->getRequest()->getContent(),
                'responseContent' => (string) $client->getResponse()->getContent(),
            ];
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @phpstan-return BodyData
     */
    private function bodyData(array $data): array
    {
        return array_intersect_key($data, [
            'http.request.body.data' => true,
            'http.response.body.data' => true,
        ]);
    }
}
