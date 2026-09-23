<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Integration\RequestIntegration;
use Sentry\SentryBundle\Integration\RequestFetcher;
use Sentry\SentrySdk;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Covers the request data that the SDK collects from the requests converted by
 * the {@see RequestFetcher}. The integration registers its event processor only
 * once per process, hence each test runs in a separate process.
 *
 * @runTestsInSeparateProcesses
 */
final class RequestIntegrationTest extends TestCase
{
    public function testUrlHeadersAndCookiesAreFiltered(): void
    {
        $request = Request::create(
            'http://www.example.com/path?token=secret&q=a%20b%26c&page=5',
            'GET',
            [],
            ['session_id' => 'foo', 'theme' => 'dark'],
            [],
            [
                'REMOTE_ADDR' => '1.2.3.4',
                'HTTP_AUTHORIZATION' => 'Bearer foo',
                'HTTP_X_REQUEST_ID' => 'bar',
                'HTTP_COOKIE' => 'session_id=foo; theme=dark',
            ]
        );

        $requestData = $this->captureRequestData($request, ['data_collection' => []]);
        /** @var array<string, string[]> $headers */
        $headers = $requestData['headers'];

        $this->assertSame('http://www.example.com/path?token=[Filtered]&q=a%20b%26c&page=5', $requestData['url']);
        $this->assertSame('token=[Filtered]&q=a%20b%26c&page=5', $requestData['query_string']);
        $this->assertSame(['REMOTE_ADDR' => '1.2.3.4'], $requestData['env']);
        $this->assertSame(['session_id' => '[Filtered]', 'theme' => 'dark'], $requestData['cookies']);
        $this->assertSame(['[Filtered]'], $headers['authorization']);
        $this->assertSame(['bar'], $headers['x-request-id']);
        $this->assertArrayNotHasKey('cookie', $headers);
        $this->assertArrayNotHasKey('data', $requestData);
    }

    /**
     * @param array<string, string>             $parameters
     * @param array<string, string>|string|null $expectedData
     *
     * @dataProvider requestBodyDataProvider
     */
    public function testRequestBodyIsFiltered(string $contentType, string $content, array $parameters, $expectedData): void
    {
        $request = Request::create('http://www.example.com/', 'POST', $parameters, [], [], [
            'CONTENT_TYPE' => $contentType,
            'CONTENT_LENGTH' => (string) \strlen($content),
        ], $content);

        $requestData = $this->captureRequestData($request, ['data_collection' => []]);

        $this->assertSame($expectedData, $requestData['data'] ?? null);
    }

    /**
     * @return \Generator<mixed>
     */
    public function requestBodyDataProvider(): \Generator
    {
        yield 'JSON body' => [
            'application/json',
            '{"username":"jane","password":"secret"}',
            [],
            ['username' => 'jane', 'password' => '[Filtered]'],
        ];

        yield 'URL encoded form body' => [
            'application/x-www-form-urlencoded',
            'username=jane&password=secret',
            ['username' => 'jane', 'password' => 'secret'],
            ['username' => 'jane', 'password' => '[Filtered]'],
        ];

        yield 'Raw body that cannot be parsed' => [
            'text/plain',
            'Hello World',
            [],
            '[Filtered]',
        ];
    }

    public function testRequestBodyIsNotCollectedWhenDisabled(): void
    {
        $request = Request::create('http://www.example.com/', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '19',
        ], '{"username":"jane"}');

        $requestData = $this->captureRequestData($request, ['data_collection' => ['http_bodies' => []]]);

        $this->assertArrayNotHasKey('data', $requestData);
    }

    public function testLegacyOptionsAreNotAffected(): void
    {
        $request = Request::create('http://www.example.com/?token=secret', 'POST', [], ['session_id' => 'foo'], [], [
            'REMOTE_ADDR' => '1.2.3.4',
            'CONTENT_TYPE' => 'text/plain',
            'CONTENT_LENGTH' => '11',
            'HTTP_AUTHORIZATION' => 'Bearer foo',
        ], 'Hello World');

        $requestData = $this->captureRequestData($request, ['send_default_pii' => false]);
        /** @var array<string, string[]> $headers */
        $headers = $requestData['headers'];

        $this->assertSame('http://www.example.com/?token=secret', $requestData['url']);
        $this->assertSame('token=secret', $requestData['query_string']);
        $this->assertArrayNotHasKey('env', $requestData);
        $this->assertArrayNotHasKey('cookies', $requestData);
        $this->assertSame(['[Filtered]'], $headers['authorization']);
        $this->assertSame('Hello World', $requestData['data']);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function captureRequestData(Request $request, array $options): array
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $events = [];
        $client = ClientBuilder::create(array_merge($options, [
            'default_integrations' => false,
            'integrations' => [new RequestIntegration(new RequestFetcher($requestStack))],
            'before_send' => static function (Event $event) use (&$events): ?Event {
                $events[] = $event;

                return null;
            },
        ]))->getClient();

        SentrySdk::getCurrentHub()->bindClient($client);
        SentrySdk::getCurrentHub()->captureMessage('foo');

        $this->assertCount(1, $events);

        return $events[0]->getRequest();
    }
}
