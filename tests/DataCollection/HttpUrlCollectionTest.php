<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DataCollection;

use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\TracingRequestListener;
use Sentry\SentryBundle\EventListener\TracingSubRequestListener;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\State\Hub;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpUrlCollectionTest extends TestCase
{
    private const QUERY = 'page=one+two&tag=a&tag=b&%74oken=secret&a.b=dot';
    private const FILTERED_QUERY = 'page=one+two&tag=a&tag=b&%74oken=[Filtered]&a.b=dot';

    public function testLegacyServerUrlUsesSymfonyRequestUri(): void
    {
        $request = Request::create('https://example.com/path?' . self::QUERY);

        [$transaction, $span] = $this->traceServerRequest(null, $request);

        $this->assertSame($request->getUri(), $transaction->getData()['http.url']);
        $this->assertSame($request->getUri(), $span->getData()['http.url']);
    }

    /**
     * @dataProvider serverUrlPolicyProvider
     *
     * @param array<string, mixed>|null $dataCollection
     */
    public function testServerUrls(?array $dataCollection, string $expectedUrl): void
    {
        $request = Request::create('https://example.com/path?' . self::QUERY);
        $originalUri = $request->getUri();

        [$transaction, $span] = $this->traceServerRequest($dataCollection, $request);

        $this->assertSame($expectedUrl, $transaction->getData()['http.url']);
        $this->assertSame('GET https://example.com/path', $transaction->getName());
        $this->assertSame($expectedUrl, $span->getData()['http.url']);
        $this->assertSame('GET https://example.com/path', $span->getDescription());
        $this->assertSame($originalUri, $request->getUri());
        $this->assertSame(self::QUERY, $request->server->get('QUERY_STRING'));
    }

    /**
     * @dataProvider clientQueryPolicyProvider
     *
     * @param array<string, mixed>|null $dataCollection
     * @param array<string, mixed>      $expectedQueryData
     */
    public function testClientQuery(?array $dataCollection, array $expectedQueryData): void
    {
        if (!class_exists(MockHttpClient::class)) {
            $this->markTestSkipped('This test requires symfony/http-client.');
        }

        $hub = $this->createHub($dataCollection);
        $transaction = new Transaction(TransactionContext::make()->setSampled(true));
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);
        $httpClient = new TraceableHttpClient(new MockHttpClient(new MockResponse()), $hub);
        $url = 'https://username:password@example.com/path?' . self::QUERY . '#fragment';
        $response = $httpClient->request('GET', $url);
        $this->assertSame(200, $response->getStatusCode());
        $untracedResponse = (new MockHttpClient(new MockResponse()))->request('GET', $url);
        $this->assertSame($untracedResponse->getInfo('url'), $response->getInfo('url'));

        $recorder = $transaction->getSpanRecorder();
        $this->assertNotNull($recorder);
        $spans = $recorder->getSpans();
        $this->assertCount(2, $spans);
        $data = $spans[1]->getData();
        $this->assertSame('https://example.com/path', $data['http.url']);
        $this->assertSame('fragment', $data['http.fragment']);
        $this->assertSame($expectedQueryData, array_intersect_key($data, ['http.query' => true]));
        $this->assertSame('GET https://example.com/path', $spans[1]->getDescription());
    }

    /**
     * @return \Generator<mixed>
     */
    public function serverUrlPolicyProvider(): \Generator
    {
        yield 'defaults' => [[], 'https://example.com/path?' . self::FILTERED_QUERY];
        yield 'off' => [['url_query_params' => ['mode' => 'off']], 'https://example.com/path'];
        yield 'deny list' => [
            ['url_query_params' => ['mode' => 'denyList', 'terms' => ['page']]],
            'https://example.com/path?page=[Filtered]&tag=a&tag=b&%74oken=[Filtered]&a.b=dot',
        ];
        yield 'allow list' => [
            ['url_query_params' => ['mode' => 'allowList', 'terms' => ['page', 'token']]],
            'https://example.com/path?page=one+two&tag=[Filtered]&tag=[Filtered]&%74oken=[Filtered]&a.b=[Filtered]',
        ];
    }

    public function clientQueryPolicyProvider(): \Generator
    {
        yield 'legacy' => [null, ['http.query' => self::QUERY]];
        yield 'defaults' => [[], ['http.query' => self::FILTERED_QUERY]];
        yield 'off' => [['url_query_params' => ['mode' => 'off']], []];
        yield 'deny list' => [
            ['url_query_params' => ['mode' => 'denyList', 'terms' => ['page']]],
            ['http.query' => 'page=[Filtered]&tag=a&tag=b&%74oken=[Filtered]&a.b=dot'],
        ];
        yield 'allow list' => [
            ['url_query_params' => ['mode' => 'allowList', 'terms' => ['page', 'token']]],
            ['http.query' => 'page=one+two&tag=[Filtered]&tag=[Filtered]&%74oken=[Filtered]&a.b=[Filtered]'],
        ];
    }

    /**
     * @param array<string, mixed>|null $dataCollection
     *
     * @return array{Transaction, \Sentry\Tracing\Span}
     */
    private function traceServerRequest(?array $dataCollection, Request $request): array
    {
        $hub = $this->createHub($dataCollection);
        $kernel = $this->createMock(HttpKernelInterface::class);
        (new TracingRequestListener($hub))->handleKernelRequestEvent(new RequestEvent(
            $kernel,
            $request,
            (int) \constant(HttpKernelInterface::class . '::' . (\defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? 'MAIN_REQUEST' : 'MASTER_REQUEST'))
        ));
        $transaction = $hub->getTransaction();
        $this->assertNotNull($transaction);
        (new TracingSubRequestListener($hub))->handleKernelRequestEvent(new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST));
        $span = $hub->getSpan();
        $this->assertNotNull($span);
        $this->assertNotSame($transaction, $span);

        return [$transaction, $span];
    }

    /**
     * @param array<string, mixed>|null $dataCollection
     */
    private function createHub(?array $dataCollection): Hub
    {
        $options = new Options(['data_collection' => $dataCollection, 'traces_sample_rate' => 1.0]);
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturn($options);

        return new Hub($client);
    }
}
