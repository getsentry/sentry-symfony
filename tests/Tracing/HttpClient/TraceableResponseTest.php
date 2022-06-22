<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Tests\Tracing\HttpClient\Fixtures\DestructibleResponseInterface;
use Sentry\SentryBundle\Tests\Tracing\HttpClient\Fixtures\StreamableResponseInterface;
use Sentry\SentryBundle\Tracing\HttpClient\TraceableResponse;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class TraceableResponseTest extends TestCase
{
    /**
     * @var MockObject&ResponseInterface
     */
    private $mockedResponse;

    /**
     * @var MockObject&HttpClientInterface
     */
    private $client;

    /**
     * @var MockObject&HubInterface
     */
    protected $hub;

    /**
     * @var TraceableResponse
     */
    private $response;

    protected function setUp(): void
    {
        $this->mockedResponse = $this->createMock(ResponseInterface::class);
        $this->client = $this->createMock(HttpClientInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
        $this->response = new TraceableResponse($this->client, $this->mockedResponse, null);
    }

    public function testCannotBeSerialized(): void
    {
        $this->expectException(\BadMethodCallException::class);
        serialize($this->response);
    }

    public function testCannotBeDeserialized(): void
    {
        $this->expectException(\BadMethodCallException::class);
        unserialize(sprintf('O:%u:"%s":0:{}', \strlen(TraceableResponse::class), TraceableResponse::class));
    }

    public function testDestructor(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $context = new SpanContext();
        $span = $transaction->startChild($context);

        $this->mockedResponse = $this->createMock(DestructibleResponseInterface::class);
        $this->mockedResponse
            ->expects($this->once())
            ->method('__destruct');

        $this->response = new TraceableResponse($this->client, $this->mockedResponse, $span);

        // Call gc to invoke destructors at the right time.
        unset($this->response);

        gc_mem_caches();
        gc_collect_cycles();

        $this->assertNotNull($span->getEndTimestamp());
    }

    public function testGetHeaders(): void
    {
        $this->mockedResponse
            ->expects($this->once())
            ->method('getHeaders')
            ->with(true);

        $this->response->getHeaders(true);
    }

    public function testGetStatusCode(): void
    {
        $this->mockedResponse
            ->expects($this->once())
            ->method('getStatusCode');

        $this->response->getStatusCode();
    }

    public function testGetContent(): void
    {
        $this->mockedResponse
            ->expects($this->once())
            ->method('getContent')
            ->with(false);

        $this->response->getContent(false);
    }

    public function testToArray(): void
    {
        $this->mockedResponse
            ->expects($this->once())
            ->method('toArray')
            ->with(false);

        $this->response->toArray(false);
    }

    public function testCancel(): void
    {
        $this->mockedResponse
            ->expects($this->once())
            ->method('cancel');

        $this->response->cancel();
    }

    public function testGetInfo(): void
    {
        $this->mockedResponse
            ->expects($this->once())
            ->method('getInfo')
            ->with('type');

        $this->response->getInfo('type');
    }

    public function testToStream(): void
    {
        if (!method_exists($this->response, 'toStream')) {
            self::markTestSkipped('Response toStream method is not existent in this version of http-client');
        }

        $this->mockedResponse = $this->createMock(StreamableResponseInterface::class);
        $this->mockedResponse
            ->expects($this->once())
            ->method('toStream')
            ->with(false);

        $this->response = new TraceableResponse($this->client, $this->mockedResponse, null);
        $this->response->toStream(false);
    }
}
