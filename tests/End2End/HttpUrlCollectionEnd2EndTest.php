<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;
use Sentry\Tracing\Span;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;

/**
 * @runTestsInSeparateProcesses
 */
final class HttpUrlCollectionEnd2EndTest extends TestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    /**
     * @dataProvider collectionProvider
     */
    public function testMainAndSubrequestUrls(bool $configured): void
    {
        $config = [__DIR__ . '/App/tracing.yml'];
        if ($configured) {
            $config[] = __DIR__ . '/App/url_data_collection.yml';
        }
        $kernel = new KernelWithExtraConfig($config);
        // Boot lazily so the SDK's first request fetcher belongs to this request's kernel.
        $client = new KernelBrowser($kernel);
        $url = 'http://localhost/subrequest?page=one+two&tag=a&tag=b&%74oken=secret&a.b=dot';
        $expectedUrl = $configured
            ? 'http://localhost/subrequest?page=one+two&tag=a&tag=b&%74oken=[Filtered]&a.b=dot'
            : Request::create($url)->getUri();

        try {
            $client->request('GET', $url);
            $this->assertSame(200, $client->getResponse()->getStatusCode());
            $request = $client->getRequest();
            $this->assertInstanceOf(Request::class, $request);
            $this->assertSame(Request::create($url)->getUri(), $request->getUri());
            $this->assertSame('page=one+two&tag=a&tag=b&%74oken=secret&a.b=dot', $request->server->get('QUERY_STRING'));
            $transactions = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return null !== $event->getTransaction();
            }));
            $this->assertCount(1, $transactions);
            $traceData = $transactions[0]->getContexts()['trace']['data'];
            $this->assertIsArray($traceData);
            $this->assertSame($expectedUrl, $traceData['http.url']);
            $spans = array_values(array_filter($transactions[0]->getSpans(), static function (Span $span): bool {
                return 'http.server' === $span->getOp();
            }));
            $this->assertCount(1, $spans);
            $this->assertSame($expectedUrl, $spans[0]->getData()['http.url']);
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return \Generator<mixed>
     */
    public function collectionProvider(): \Generator
    {
        yield 'legacy' => [false];
        yield 'configured' => [true];
    }
}
