<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithHttpHeaders;
use Sentry\Tracing\Span;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @runTestsInSeparateProcesses
 *
 * @phpstan-type Exchange array{spans: array<string, array<string, mixed>>, event: Event, request: Request, response: Response, eventRequest: array{headers?: array<string, string[]>, cookies?: array<string, mixed>}}
 */
final class HttpHeaderCollectionEnd2EndTest extends TestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testDefaultHeadersAreCollectedForMainAndSubrequests(bool $pii): void
    {
        $result = $this->request(['data_collection' => [], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($result['spans']);
        $this->assertArrayHasKey('headers', $result['eventRequest']);
        $this->assertSame(['[Filtered]'], $result['eventRequest']['headers']['authorization']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testDefaultCookiesAreCollectedForMainAndSubrequests(bool $pii): void
    {
        $result = $this->request(['data_collection' => [], 'send_default_pii' => $pii]);

        $this->assertCollectedCookies($result['spans']);
        $this->assertArrayHasKey('cookies', $result['eventRequest']);
        $this->assertSame('dark', $result['eventRequest']['cookies']['theme']);
        $this->assertSame('[Filtered]', $result['eventRequest']['cookies']['session_id']);
    }

    /**
     * @dataProvider legacyHeadersProvider
     */
    public function testLegacyConfigurationPreservesEventHeadersWithoutAddingSpanHeaders(bool $pii, string $authorization): void
    {
        $result = $this->request(['send_default_pii' => $pii]);

        foreach ($result['spans'] as $data) {
            $this->assertArrayNotHasKey('http.request.header.x-test', $data);
            $this->assertArrayNotHasKey('http.response.header.x-test', $data);
            $this->assertArrayNotHasKey('http.request.header.cookie.theme', $data);
            $this->assertArrayNotHasKey('http.response.header.set_cookie.theme', $data);
        }
        $this->assertArrayHasKey('headers', $result['eventRequest']);
        $this->assertSame([$authorization], $result['eventRequest']['headers']['authorization']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testRequestHeadersCanBeDisabledIndependently(bool $pii): void
    {
        $result = $this->request(['data_collection' => ['http_headers' => ['request' => ['mode' => 'off']]], 'send_default_pii' => $pii]);

        foreach ($result['spans'] as $name => $data) {
            $this->assertArrayNotHasKey('http.request.header.x-test', $data);
            $this->assertArrayNotHasKey('http.request.header.authorization', $data);
            $this->assertSame([$name, 'second'], $data['http.response.header.x-test']);
            $this->assertSame(['[Filtered]'], $data['http.response.header.authorization']);
        }
        $this->assertArrayNotHasKey('headers', $result['eventRequest']);
        $this->assertCollectedCookies($result['spans']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testResponseHeadersCanBeDisabledIndependently(bool $pii): void
    {
        $result = $this->request(['data_collection' => ['http_headers' => ['response' => ['mode' => 'off']]], 'send_default_pii' => $pii]);

        foreach ($result['spans'] as $name => $data) {
            $this->assertSame([$name], $data['http.request.header.x-test']);
            $this->assertSame(['[Filtered]'], $data['http.request.header.authorization']);
            $this->assertArrayNotHasKey('http.response.header.x-test', $data);
            $this->assertArrayNotHasKey('http.response.header.authorization', $data);
        }
        $this->assertArrayHasKey('headers', $result['eventRequest']);
        $this->assertSame(['[Filtered]'], $result['eventRequest']['headers']['authorization']);
        $this->assertCollectedCookies($result['spans']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testDisablingBothHeaderDirectionsStillCollectsCookies(bool $pii): void
    {
        $result = $this->request(['data_collection' => ['http_headers' => ['mode' => 'off']], 'send_default_pii' => $pii]);

        foreach ($result['spans'] as $data) {
            $this->assertArrayNotHasKey('http.request.header.x-test', $data);
            $this->assertArrayNotHasKey('http.response.header.x-test', $data);
        }
        $this->assertArrayNotHasKey('headers', $result['eventRequest']);
        $this->assertCollectedCookies($result['spans']);
        $this->assertArrayHasKey('cookies', $result['eventRequest']);
        $this->assertSame('dark', $result['eventRequest']['cookies']['theme']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testDisablingCookiesStillCollectsHeaders(bool $pii): void
    {
        $result = $this->request(['data_collection' => ['cookies' => ['mode' => 'off']], 'send_default_pii' => $pii]);

        foreach ($result['spans'] as $data) {
            $this->assertArrayNotHasKey('http.request.header.cookie.theme', $data);
            $this->assertArrayNotHasKey('http.request.header.cookie.session_id', $data);
            $this->assertArrayNotHasKey('http.response.header.set_cookie.theme', $data);
            $this->assertArrayNotHasKey('http.response.header.set_cookie.session_id', $data);
        }
        $this->assertArrayNotHasKey('cookies', $result['eventRequest']);
        $this->assertCollectedHeaders($result['spans']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testHeadersAndCookiesCanBothBeDisabled(bool $pii): void
    {
        $result = $this->request(['data_collection' => ['http_headers' => ['mode' => 'off'], 'cookies' => ['mode' => 'off']], 'send_default_pii' => $pii]);

        foreach ($result['spans'] as $data) {
            foreach (['http.request.header.x-test', 'http.response.header.x-test', 'http.request.header.authorization', 'http.response.header.authorization', 'http.request.header.cookie.theme', 'http.response.header.set_cookie.theme', 'http.request.header.cookie.session_id', 'http.response.header.set_cookie.session_id'] as $key) {
                $this->assertArrayNotHasKey($key, $data);
            }
        }
        $this->assertArrayNotHasKey('headers', $result['eventRequest']);
        $this->assertArrayNotHasKey('cookies', $result['eventRequest']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testHeaderAllowListKeepsExactMatchesAndFiltersSensitiveValues(bool $pii): void
    {
        $result = $this->request(['data_collection' => ['http_headers' => ['mode' => 'allowList', 'terms' => ['x-test', 'authorization']]], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($result['spans'], '[Filtered]', '[Filtered]');
        $this->assertArrayHasKey('headers', $result['eventRequest']);
        $this->assertSame(['[Filtered]'], $result['eventRequest']['headers']['authorization']);
        $this->assertSame(['[Filtered]'], $result['eventRequest']['headers']['x-debug']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testHeaderDenyListFiltersPartialCustomMatches(bool $pii): void
    {
        $result = $this->request(['data_collection' => ['http_headers' => ['mode' => 'denyList', 'terms' => ['x-debug']]], 'send_default_pii' => $pii]);

        $this->assertCollectedHeaders($result['spans'], '[Filtered]', '[Filtered]', 'visible');
        $this->assertArrayHasKey('headers', $result['eventRequest']);
        $this->assertSame(['[Filtered]'], $result['eventRequest']['headers']['x-debug']);
        $this->assertSame(['[Filtered]'], $result['eventRequest']['headers']['x-debug-extra']);
    }

    /**
     * @dataProvider legacyPiiProvider
     */
    public function testCookieAllowListFiltersUnlistedAndSensitiveCookies(bool $pii): void
    {
        $result = $this->request(['data_collection' => ['cookies' => ['mode' => 'allowList', 'terms' => ['session']]], 'send_default_pii' => $pii]);

        foreach ($result['spans'] as $data) {
            $this->assertSame('[Filtered]', $data['http.request.header.cookie.theme']);
            $this->assertSame('[Filtered]', $data['http.request.header.cookie.session_id']);
            $this->assertSame('[Filtered]', $data['http.response.header.set_cookie.theme']);
            $this->assertSame('[Filtered]', $data['http.response.header.set_cookie.session_id']);
        }
        $this->assertArrayHasKey('cookies', $result['eventRequest']);
        $this->assertSame('[Filtered]', $result['eventRequest']['cookies']['theme']);
        $this->assertSame('[Filtered]', $result['eventRequest']['cookies']['session_id']);
    }

    /**
     * @dataProvider userCollectionEnabledProvider
     *
     * @param array<string, mixed> $options
     */
    public function testUserIpIsCollectedOnEventsAndTransactions(array $options): void
    {
        $result = $this->request($options);
        $user = $result['event']->getUser();

        $this->assertNotNull($user);
        $this->assertSame($result['request']->getClientIp(), $user->getIpAddress());
        $this->assertSame($result['request']->getClientIp(), $result['spans']['main']['net.peer.ip']);
    }

    /**
     * @dataProvider userCollectionDisabledProvider
     *
     * @param array<string, mixed> $options
     */
    public function testDisabledUserInfoDoesNotAddIpToEventsOrTransactions(array $options): void
    {
        $result = $this->request($options);

        $this->assertNull($result['event']->getUser());
        $this->assertArrayNotHasKey('net.peer.ip', $result['spans']['main']);
    }

    /**
     * @dataProvider explicitDataProvider
     *
     * @param array<string, mixed> $options
     */
    public function testExplicitSpanValuesArePreserved(array $options): void
    {
        $result = $this->request($options);

        foreach ($result['spans'] as $data) {
            $this->assertArrayHasKey('http.response.header.x-explicit', $data);
            $this->assertNull($data['http.response.header.x-explicit']);
            $this->assertArrayHasKey('http.response.header.set_cookie.explicit', $data);
            $this->assertNull($data['http.response.header.set_cookie.explicit']);
        }
    }

    /**
     * @dataProvider rawCookieHeadersProvider
     *
     * @param array<string, mixed> $collection
     */
    public function testRawCookieHeadersAreExcluded(array $collection): void
    {
        $result = $this->request(['data_collection' => $collection]);

        foreach ($result['spans'] as $data) {
            foreach (['http.request.header.cookie', 'http.request.header.set-cookie', 'http.response.header.cookie', 'http.response.header.set-cookie'] as $key) {
                $this->assertArrayNotHasKey($key, $data);
            }
        }
        $this->assertArrayNotHasKey('cookie', $result['eventRequest']['headers'] ?? []);
        $this->assertArrayNotHasKey('set-cookie', $result['eventRequest']['headers'] ?? []);
    }

    public function testCollectionDoesNotModifyTheRequestOrResponse(): void
    {
        $result = $this->request(['data_collection' => []]);

        $this->assertSame('Bearer request-secret', $result['request']->headers->get('Authorization'));
        $this->assertSame('main', $result['request']->headers->get('X-Test'));
        $this->assertSame('response-secret', $result['response']->headers->get('Authorization'));
        $this->assertSame('response-cookie', $result['response']->headers->getCookies()[0]->getValue());
    }

    public function legacyPiiProvider(): \Generator
    {
        yield 'legacy PII disabled' => [false];
        yield 'legacy PII enabled' => [true];
    }

    public function legacyHeadersProvider(): \Generator
    {
        yield 'legacy PII disabled' => [false, '[Filtered]'];
        yield 'legacy PII enabled' => [true, 'Bearer request-secret'];
    }

    public function userCollectionEnabledProvider(): \Generator
    {
        yield 'legacy enabled' => [['send_default_pii' => true]];
        yield 'configured defaults override legacy off' => [['data_collection' => [], 'send_default_pii' => false]];
        yield 'configured defaults with legacy on' => [['data_collection' => [], 'send_default_pii' => true]];
    }

    public function userCollectionDisabledProvider(): \Generator
    {
        yield 'legacy disabled' => [['send_default_pii' => false]];
        yield 'user info off with legacy off' => [['data_collection' => ['user_info' => false], 'send_default_pii' => false]];
        yield 'user info off overrides legacy on' => [['data_collection' => ['user_info' => false], 'send_default_pii' => true]];
    }

    public function explicitDataProvider(): \Generator
    {
        yield 'legacy off' => [['send_default_pii' => false]];
        yield 'legacy on' => [['send_default_pii' => true]];
        yield 'configured defaults' => [['data_collection' => []]];
        yield 'headers off' => [['data_collection' => ['http_headers' => ['mode' => 'off']]]];
        yield 'cookies off' => [['data_collection' => ['cookies' => ['mode' => 'off']]]];
        yield 'all off' => [['data_collection' => ['http_headers' => ['mode' => 'off'], 'cookies' => ['mode' => 'off']]]];
    }

    public function rawCookieHeadersProvider(): \Generator
    {
        yield 'configured defaults' => [[]];
        yield 'headers off' => [['http_headers' => ['mode' => 'off']]];
        yield 'cookies off' => [['cookies' => ['mode' => 'off']]];
        yield 'explicitly allowed header names' => [['http_headers' => ['mode' => 'allowList', 'terms' => ['cookie', 'set-cookie']]]];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @phpstan-return Exchange
     */
    private function request(array $options): array
    {
        $kernel = new KernelWithHttpHeaders($options);
        $client = new KernelBrowser($kernel);
        $client->getCookieJar()->set(new Cookie('theme', 'dark'));
        $client->getCookieJar()->set(new Cookie('session_id', 'request-cookie'));
        try {
            $client->request('GET', '/header-collection', [], [], [
                'HTTP_AUTHORIZATION' => 'Bearer request-secret',
                'HTTP_X_TEST' => 'main',
                'HTTP_X_DEBUG' => 'visible',
                'HTTP_X_DEBUG_EXTRA' => 'visible',
                'HTTP_X_TEST_EXTRA' => 'visible',
                'HTTP_COOKIE' => 'session_id=request-cookie',
            ]);
            $request = $client->getRequest();
            $this->assertInstanceOf(Request::class, $request);
            $response = $client->getResponse();
            $this->assertSame(200, $response->getStatusCode());
            $transactions = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return null !== $event->getTransaction();
            }));
            $this->assertCount(1, $transactions);
            $mainData = $transactions[0]->getContexts()['trace']['data'];
            $this->assertIsArray($mainData);
            $subspans = array_values(array_filter($transactions[0]->getSpans(), static function (Span $span): bool {
                return 'http.server' === $span->getOp();
            }));
            $this->assertCount(1, $subspans);
            $messages = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return 'Header collection' === $event->getMessage();
            }));
            $this->assertCount(1, $messages);
            /** @var array{headers?: array<string, string[]>, cookies?: array<string, mixed>} $eventRequest */
            $eventRequest = $messages[0]->getRequest();

            return [
                'spans' => ['main' => $mainData, 'subrequest' => $subspans[0]->getData()],
                'event' => $messages[0],
                'eventRequest' => $eventRequest,
                'request' => $request,
                'response' => $response,
            ];
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @param array<string, array<string, mixed>> $spans
     */
    private function assertCollectedHeaders(array $spans, string $debugValue = 'visible', string $extraValue = 'visible', ?string $testExtraValue = null): void
    {
        $testExtraValue = $testExtraValue ?? $extraValue;
        foreach ($spans as $name => $data) {
            $this->assertSame([$name], $data['http.request.header.x-test']);
            $this->assertSame([$name, 'second'], $data['http.response.header.x-test']);
            foreach (['http.request.header.', 'http.response.header.'] as $prefix) {
                $this->assertSame(['[Filtered]'], $data[$prefix . 'authorization']);
                $this->assertSame([$debugValue], $data[$prefix . 'x-debug']);
                $this->assertSame([$extraValue], $data[$prefix . 'x-debug-extra']);
                $this->assertSame([$testExtraValue], $data[$prefix . 'x-test-extra']);
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $spans
     */
    private function assertCollectedCookies(array $spans): void
    {
        $this->assertSame('dark', $spans['main']['http.request.header.cookie.theme']);
        $this->assertSame('light', $spans['main']['http.response.header.set_cookie.theme']);
        $this->assertSame('subrequest', $spans['subrequest']['http.request.header.cookie.theme']);
        $this->assertSame('subrequest', $spans['subrequest']['http.response.header.set_cookie.theme']);
        foreach ($spans as $data) {
            $this->assertSame('[Filtered]', $data['http.request.header.cookie.session_id']);
            $this->assertSame('[Filtered]', $data['http.response.header.set_cookie.session_id']);
        }
    }
}
