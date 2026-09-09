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
    /**
     * @dataProvider policyProvider
     *
     * @param array{http_headers?: array{mode?: string}, cookies?: array{mode?: string}, http_bodies?: string[]}|null $collection
     */
    public function testOutgoingHttpData(?array $collection, bool $pii, bool $stream, bool $asArray = false, string $extraQuery = ''): void
    {
        if (!class_exists(MockHttpClient::class)) {
            $this->markTestSkipped('This test requires symfony/http-client.');
        }
        if ('defaults=1' === $extraQuery && !method_exists(MockHttpClient::class, 'withOptions')) {
            $this->markTestSkipped('withOptions is not available.');
        }
        StubTransport::$events = [];
        $options = ['send_default_pii' => $pii];
        if (null !== $collection) {
            $options['data_collection'] = $collection;
        }
        $kernel = new KernelWithHttpHeaders($options);
        $client = new KernelBrowser($kernel);
        try {
            $client->request('GET', '/http-client-collection?' . ($stream ? 'stream=1' : ($asArray ? 'array=1' : $extraQuery)));
            $this->assertSame('{"name":"Bob","token":"secret"}', $client->getResponse()->getContent());
            /** @var Event[] $events */
            $events = StubTransport::$events;
            $transactions = array_values(array_filter($events, static function (Event $event): bool {
                return null !== $event->getTransaction();
            }));
            $this->assertCount(1, $transactions);
            $spans = array_values(array_filter($transactions[0]->getSpans(), static function (Span $span): bool {
                return 'http.client' === $span->getOp();
            }));
            $this->assertCount(1, $spans);
            $data = $spans[0]->getData();
            if ('body=1' === $extraQuery) {
                $this->assertArrayNotHasKey('http.request.header.content-type', $data);
                $this->assertArrayNotHasKey('http.request.header.content-length', $data);
            }
            $this->assertSame('search=old', $data['http.query']);
            foreach (['request' => 'cookie', 'response' => 'set_cookie'] as $direction => $cookieAttribute) {
                $prefix = 'http.' . $direction . '.';
                $collected = null !== $collection;
                if ($collected && 'off' !== ($collection['http_headers']['mode'] ?? null)) {
                    $this->assertSame([$direction], $data[$prefix . 'header.x-test']);
                } else {
                    $this->assertArrayNotHasKey($prefix . 'header.x-test', $data);
                }
                if ($collected && 'off' !== ($collection['cookies']['mode'] ?? null)) {
                    $this->assertSame('request' === $direction ? 'dark' : 'light', $data[$prefix . 'header.' . $cookieAttribute . '.theme']);
                    $this->assertSame('[Filtered]', $data[$prefix . 'header.' . $cookieAttribute . '.session_id']);
                } else {
                    $this->assertArrayNotHasKey($prefix . 'header.' . $cookieAttribute . '.theme', $data);
                }
                $this->assertArrayNotHasKey($prefix . 'body.data', $data);
                $this->assertArrayNotHasKey($prefix . 'header.cookie', $data);
                $this->assertArrayNotHasKey($prefix . 'header.set-cookie', $data);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    public function policyProvider(): \Generator
    {
        foreach ([false, true] as $pii) {
            foreach ([null, [], ['http_headers' => ['mode' => 'off']], ['cookies' => ['mode' => 'off']], ['http_bodies' => []]] as $collection) {
                yield [$collection, $pii, false];
            }
        }
        yield [[], false, true];
        yield 'parsed response without size metadata' => [[], false, false, true];
        yield 'client defaults' => [[], false, false, false, 'defaults=1'];
        yield 'generated body headers' => [[], false, false, false, 'body=1'];
        yield 'body read after HTTP exception' => [[], false, false, false, 'error=1'];
    }
}
