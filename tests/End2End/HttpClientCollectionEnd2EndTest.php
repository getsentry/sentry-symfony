<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithHttpHeaders;
use Sentry\Tracing\Span;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * @runTestsInSeparateProcesses
 */
final class HttpClientCollectionEnd2EndTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(MockHttpClient::class)) {
            $this->markTestSkipped('This test requires symfony/http-client.');
        }
        StubTransport::$events = [];
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testConfiguredDefaultsCollectHeadersAndCookies(bool $pii): void
    {
        $data = $this->request(['data_collection' => [], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($data);
        $this->assertCollectedCookies($data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testLegacyConfigurationDoesNotAddHeadersOrCookies(bool $pii): void
    {
        $data = $this->request(['send_default_pii' => $pii]);

        $this->assertArrayNotHasKey('http.request.header.x-test', $data);
        $this->assertArrayNotHasKey('http.response.header.x-test', $data);
        $this->assertArrayNotHasKey('http.request.header.cookie.theme', $data);
        $this->assertArrayNotHasKey('http.response.header.set_cookie.theme', $data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testDisablingHeadersStillCollectsCookies(bool $pii): void
    {
        $data = $this->request(['data_collection' => ['http_headers' => ['mode' => 'off']], 'send_default_pii' => $pii]);

        $this->assertArrayNotHasKey('http.request.header.x-test', $data);
        $this->assertArrayNotHasKey('http.response.header.x-test', $data);
        $this->assertCollectedCookies($data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testDisablingCookiesStillCollectsHeaders(bool $pii): void
    {
        $data = $this->request(['data_collection' => ['cookies' => ['mode' => 'off']], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($data);
        $this->assertArrayNotHasKey('http.request.header.cookie.theme', $data);
        $this->assertArrayNotHasKey('http.response.header.set_cookie.theme', $data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testDisablingBodiesStillCollectsHeadersAndCookies(bool $pii): void
    {
        $data = $this->request(['data_collection' => ['http_bodies' => []], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($data);
        $this->assertCollectedCookies($data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    public function testStreamingCollectsHeadersAndCookies(): void
    {
        $data = $this->request(['data_collection' => []], 'stream=1');

        $this->assertCollectedHeaders($data);
        $this->assertCollectedCookies($data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    public function testDecodingJsonCollectsHeadersAndCookies(): void
    {
        $data = $this->request(['data_collection' => []], 'array=1');

        $this->assertCollectedHeaders($data);
        $this->assertCollectedCookies($data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    public function testClientDefaultsAreCollected(): void
    {
        if (!method_exists(MockHttpClient::class, 'withOptions')) {
            $this->markTestSkipped('withOptions is not available.');
        }
        $data = $this->request(['data_collection' => []], 'defaults=1');

        $this->assertCollectedHeaders($data);
        $this->assertCollectedCookies($data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    public function testGeneratedBodyHeadersAreNotCollected(): void
    {
        $data = $this->request(['data_collection' => []], 'body=1');

        $this->assertArrayNotHasKey('http.request.header.content-type', $data);
        $this->assertArrayNotHasKey('http.request.header.content-length', $data);
        $this->assertCollectedHeaders($data);
        $this->assertCollectedCookies($data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    public function testHttpErrorResponsesCollectHeadersAndCookies(): void
    {
        $data = $this->request(['data_collection' => []], 'error=1');

        $this->assertCollectedHeaders($data);
        $this->assertCollectedCookies($data);
        $this->assertNoBodiesOrRawCookieHeaders($data);
    }

    public function legacyPiiProvider(): \Generator
    {
        yield 'legacy PII disabled' => [false];
        yield 'legacy PII enabled' => [true];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(array $options, string $query = ''): array
    {
        $kernel = new KernelWithHttpHeaders($options);
        $client = new KernelBrowser($kernel);
        try {
            $client->request('GET', '/http-client-collection?' . $query);
            $this->assertSame('{"name":"Bob","token":"secret"}', $client->getResponse()->getContent());
            $transactions = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return null !== $event->getTransaction();
            }));
            $this->assertCount(1, $transactions);
            $spans = array_values(array_filter($transactions[0]->getSpans(), static function (Span $span): bool {
                return 'http.client' === $span->getOp();
            }));
            $this->assertCount(1, $spans);
            $data = $spans[0]->getData();
            $this->assertSame('search=old', $data['http.query']);

            return $data;
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertCollectedHeaders(array $data): void
    {
        $this->assertSame(['request'], $data['http.request.header.x-test']);
        $this->assertSame(['response'], $data['http.response.header.x-test']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertCollectedCookies(array $data): void
    {
        $this->assertSame('dark', $data['http.request.header.cookie.theme']);
        $this->assertSame('light', $data['http.response.header.set_cookie.theme']);
        $this->assertSame('[Filtered]', $data['http.request.header.cookie.session_id']);
        $this->assertSame('[Filtered]', $data['http.response.header.set_cookie.session_id']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertNoBodiesOrRawCookieHeaders(array $data): void
    {
        foreach (['http.request.body.data', 'http.response.body.data', 'http.request.header.cookie', 'http.request.header.set-cookie', 'http.response.header.cookie', 'http.response.header.set-cookie'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
    }
}
