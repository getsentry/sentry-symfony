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

/**
 * @runTestsInSeparateProcesses
 */
final class HttpHeaderCollectionEnd2EndTest extends TestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    /**
     * @dataProvider policyProvider
     *
     * @param array{user_info?: bool, http_headers?: array{mode?: string, request?: array{mode?: string}, response?: array{mode?: string}, terms?: string[]}, cookies?: array{mode?: string, terms?: string[]}}|null $collection
     */
    public function testServerHeaders(?array $collection, bool $pii, bool $requestHeaders, bool $responseHeaders, string $debugValue): void
    {
        $options = ['send_default_pii' => $pii];
        if (null !== $collection) {
            $options['data_collection'] = $collection;
        }
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
            $response = $client->getResponse();
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('response-secret', $response->headers->get('Authorization'));
            $this->assertSame('response-cookie', $response->headers->getCookies()[0]->getValue());
            $request = $client->getRequest();
            $this->assertInstanceOf(Request::class, $request);
            $this->assertSame('Bearer request-secret', $request->headers->get('Authorization'));
            $this->assertSame('main', $request->headers->get('X-Test'));

            $transactions = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return null !== $event->getTransaction();
            }));
            $this->assertCount(1, $transactions);
            $mainData = $transactions[0]->getContexts()['trace']['data'];
            $this->assertIsArray($mainData);
            $collectUserInfo = null === $collection ? $pii : ($collection['user_info'] ?? true);
            if ($collectUserInfo) {
                $this->assertSame($request->getClientIp(), $mainData['net.peer.ip']);
            } else {
                $this->assertArrayNotHasKey('net.peer.ip', $mainData);
            }
            $subspans = array_values(array_filter($transactions[0]->getSpans(), static function (Span $span): bool {
                return 'http.server' === $span->getOp();
            }));
            $this->assertCount(1, $subspans);
            foreach (['main' => $mainData, 'subrequest' => $subspans[0]->getData()] as $name => $data) {
                $this->assertArrayHasKey('http.response.header.x-explicit', $data);
                $this->assertNull($data['http.response.header.x-explicit']);
                $this->assertArrayHasKey('http.response.header.set_cookie.explicit', $data);
                $this->assertNull($data['http.response.header.set_cookie.explicit']);
                $cookiesEnabled = null !== $collection && 'off' !== ($collection['cookies']['mode'] ?? null);
                $themeFiltered = isset($collection['cookies']['terms']) && ['session'] === $collection['cookies']['terms'];
                foreach (['request' => 'cookie', 'response' => 'set_cookie'] as $direction => $attribute) {
                    $prefix = 'http.' . $direction . '.header.' . $attribute . '.';
                    if ($cookiesEnabled) {
                        $theme = 'subrequest' === $name ? 'subrequest' : ('request' === $direction ? 'dark' : 'light');
                        $this->assertSame($themeFiltered ? '[Filtered]' : $theme, $data[$prefix . 'theme']);
                        $this->assertSame('[Filtered]', $data[$prefix . 'session_id']);
                    } else {
                        $this->assertArrayNotHasKey($prefix . 'theme', $data);
                        $this->assertArrayNotHasKey($prefix . 'session_id', $data);
                    }
                }
                foreach (['request' => $requestHeaders, 'response' => $responseHeaders] as $direction => $enabled) {
                    $prefix = 'http.' . $direction . '.header.';
                    if ($enabled) {
                        $this->assertSame('request' === $direction ? [$name] : [$name, 'second'], $data[$prefix . 'x-test']);
                        $this->assertSame(['[Filtered]'], $data[$prefix . 'authorization']);
                        $this->assertSame([$debugValue], $data[$prefix . 'x-debug']);
                        $extraValue = 'allowList' === ($collection['http_headers']['mode'] ?? null) ? '[Filtered]' : 'visible';
                        $this->assertSame([$extraValue], $data[$prefix . 'x-debug-extra']);
                        $this->assertSame([$extraValue], $data[$prefix . 'x-test-extra']);
                    } else {
                        $this->assertArrayNotHasKey($prefix . 'x-test', $data);
                        $this->assertArrayNotHasKey($prefix . 'authorization', $data);
                    }
                }
                foreach (['request', 'response'] as $direction) {
                    $this->assertArrayNotHasKey('http.' . $direction . '.header.cookie', $data);
                    $this->assertArrayNotHasKey('http.' . $direction . '.header.set-cookie', $data);
                }
            }

            // Request events retain their SDK-owned legacy behavior and configuration precedence.
            $messages = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return 'Header collection' === $event->getMessage();
            }));
            $this->assertCount(1, $messages);
            $user = $messages[0]->getUser();
            $this->assertSame($collectUserInfo ? $request->getClientIp() : null, null === $user ? null : $user->getIpAddress());
            /** @var array{headers?: array<string, string[]>, cookies?: array<string, string>} $eventRequest */
            $eventRequest = $messages[0]->getRequest();
            if (null !== $collection) {
                $this->assertArrayNotHasKey('cookie', $eventRequest['headers'] ?? []);
                $this->assertArrayNotHasKey('set-cookie', $eventRequest['headers'] ?? []);
                if ('off' === ($collection['cookies']['mode'] ?? null)) {
                    $this->assertArrayNotHasKey('cookies', $eventRequest);
                } else {
                    $this->assertArrayHasKey('cookies', $eventRequest);
                    $this->assertSame(isset($collection['cookies']['terms']) ? '[Filtered]' : 'dark', $eventRequest['cookies']['theme']);
                    $this->assertSame('[Filtered]', $eventRequest['cookies']['session_id']);
                }
            }
            if (null === $collection || $requestHeaders) {
                $this->assertArrayHasKey('headers', $eventRequest);
                $eventHeaders = $eventRequest['headers'];
                $this->assertIsArray($eventHeaders);
                $this->assertSame([null === $collection && $pii ? 'Bearer request-secret' : '[Filtered]'], $eventHeaders['authorization']);
            } else {
                $this->assertArrayNotHasKey('headers', $eventRequest);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return \Generator<mixed>
     */
    public function policyProvider(): \Generator
    {
        foreach ([false, true] as $pii) {
            $suffix = ' pii=' . (int) $pii;
            yield 'legacy' . $suffix => [null, $pii, false, false, 'visible'];
            yield 'defaults' . $suffix => [[], $pii, true, true, 'visible'];
            yield 'user info off' . $suffix => [['user_info' => false], $pii, true, true, 'visible'];
            yield 'request off' . $suffix => [['http_headers' => ['request' => ['mode' => 'off']]], $pii, false, true, 'visible'];
            yield 'response off' . $suffix => [['http_headers' => ['response' => ['mode' => 'off']]], $pii, true, false, 'visible'];
            yield 'cookies off' . $suffix => [['cookies' => ['mode' => 'off']], $pii, true, true, 'visible'];
            yield 'cookie allow list' . $suffix => [['cookies' => ['mode' => 'allowList', 'terms' => ['session']]], $pii, true, true, 'visible'];
            yield 'all off' . $suffix => [['http_headers' => ['mode' => 'off'], 'cookies' => ['mode' => 'off']], $pii, false, false, 'visible'];
            yield 'cookies enabled, headers off' . $suffix => [['http_headers' => ['mode' => 'off']], $pii, false, false, 'visible'];
            yield 'allow list' . $suffix => [['http_headers' => ['mode' => 'allowList', 'terms' => ['x-test', 'authorization']]], $pii, true, true, '[Filtered]'];
            yield 'deny list' . $suffix => [['http_headers' => ['mode' => 'denyList', 'terms' => ['x-debug']]], $pii, true, true, '[Filtered]'];
        }
    }
}
