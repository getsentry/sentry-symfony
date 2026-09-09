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

    public function testLegacyUrlsUseSymfonyNormalization(): void
    {
        $url = 'http://localhost/subrequest?page=one+two&tag=a&tag=b&%74oken=secret&a.b=dot';
        $this->assertRequestUrls([__DIR__ . '/App/tracing.yml'], Request::create($url)->getUri());
    }

    public function testConfiguredUrlsPreserveEncodingAndFilterSensitiveValues(): void
    {
        $this->assertRequestUrls(
            [__DIR__ . '/App/tracing.yml', __DIR__ . '/App/url_data_collection.yml'],
            'http://localhost/subrequest?page=one+two&tag=a&tag=b&%74oken=[Filtered]&a.b=dot'
        );
    }

    /**
     * @param string[] $config
     */
    private function assertRequestUrls(array $config, string $expectedUrl): void
    {
        $kernel = new KernelWithExtraConfig($config);
        // Boot lazily so the SDK's first request fetcher belongs to this request's kernel.
        $client = new KernelBrowser($kernel);
        $url = 'http://localhost/subrequest?page=one+two&tag=a&tag=b&%74oken=secret&a.b=dot';

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
}
