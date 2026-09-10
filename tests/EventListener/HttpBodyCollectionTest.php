<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\AbstractTracingRequestListener;
use Sentry\State\Hub;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpBodyCollectionTest extends TestCase
{
    /**
     * @dataProvider disabledCollectionProvider
     *
     * @param array<string, mixed> $options
     */
    public function testDisabledBodiesAreNotRead(array $options): void
    {
        $request = $this->requestWhoseBodyMustNotBeRead();
        $response = $this->responseWhoseBodyMustNotBeRead();

        $span = $this->collect($request, $response, $options);

        $this->assertArrayNotHasKey('http.request.body.data', $span->getData());
        $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
    }

    public function disabledCollectionProvider(): \Generator
    {
        yield 'legacy' => [[]];
        yield 'legacy PII enabled' => [['send_default_pii' => true]];
        yield 'bodies disabled' => [['data_collection' => ['http_bodies' => []]]];
        yield 'bodies disabled overrides PII' => [['data_collection' => ['http_bodies' => []], 'send_default_pii' => true]];
    }

    public function testUnsampledBodiesAreNotRead(): void
    {
        $span = new Transaction(TransactionContext::make()->setSampled(false));

        $this->collect($this->requestWhoseBodyMustNotBeRead(), $this->responseWhoseBodyMustNotBeRead(), ['data_collection' => []], $span);

        $this->assertArrayNotHasKey('http.request.body.data', $span->getData());
        $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
    }

    public function testExplicitBodyDataIsPreservedWithoutReadingBodies(): void
    {
        $span = new Transaction(TransactionContext::make()->setSampled(true));
        $span->setData([
            'http.request.body.data' => null,
            'http.response.body.data' => null,
        ]);

        $this->collect($this->requestWhoseBodyMustNotBeRead(), $this->responseWhoseBodyMustNotBeRead(), ['data_collection' => []], $span);

        $this->assertNull($span->getData()['http.request.body.data']);
        $this->assertNull($span->getData()['http.response.body.data']);
    }

    public function testStreamedResponseIsNotCollected(): void
    {
        $response = new StreamedResponse(static function (): void {
            throw new \LogicException('Must not invoke output callbacks');
        });

        $span = $this->collect(Request::create('/'), $response, ['data_collection' => []]);

        $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
    }

    public function testFileResponseIsNotCollected(): void
    {
        $span = $this->collect(Request::create('/'), new BinaryFileResponse(__FILE__), ['data_collection' => []]);

        $this->assertArrayNotHasKey('http.response.body.data', $span->getData());
    }

    public function testParsedFormDataIsFilteredWithoutChangingRequest(): void
    {
        $request = Request::create('/', 'POST', ['name' => 'Alice', 'password' => 'secret']);
        $span = $this->collect($request, new Response(), ['data_collection' => []]);

        $this->assertSame(['name' => 'Alice', 'password' => '[Filtered]'], $span->getData()['http.request.body.data']);
        $this->assertSame('secret', $request->request->get('password'));
    }

    public function testOversizedRequestIsNotRead(): void
    {
        $request = $this->getMockBuilder(Request::class)->onlyMethods(['getContent'])->getMock();
        $request->headers->set('Content-Length', '1001');
        $request->expects($this->never())->method('getContent');

        $span = $this->collect($request, new Response(), ['data_collection' => [], 'max_request_body_size' => 'small']);
        $this->assertArrayNotHasKey('http.request.body.data', $span->getData());
    }

    /**
     * @param array<string, mixed> $options
     */
    private function collect(Request $request, Response $response, array $options, ?Transaction $span = null): Transaction
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturn(new Options($options + ['max_request_body_size' => 'always']));
        $hub = new Hub($client);
        $span = $span ?? new Transaction(TransactionContext::make()->setSampled(true));
        $hub->setSpan($span);
        $type = (int) \constant(HttpKernelInterface::class . '::' . (\defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? 'MAIN_REQUEST' : 'MASTER_REQUEST'));
        $listener = new class($hub) extends AbstractTracingRequestListener {
            public function collectRequest(\Sentry\Tracing\Span $span, Request $request): void
            {
                $this->collectRequestData($span, $request, DataCollectionPolicy::fromHub($this->hub));
            }
        };
        $listener->collectRequest($span, $request);
        $listener->collectKernelResponseEvent(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, $type, $response));

        return $span;
    }

    private function requestWhoseBodyMustNotBeRead(): Request
    {
        $request = $this->getMockBuilder(Request::class)->onlyMethods(['getContent'])->getMock();
        $request->expects($this->never())->method('getContent');

        return $request;
    }

    private function responseWhoseBodyMustNotBeRead(): Response
    {
        $response = $this->getMockBuilder(Response::class)->onlyMethods(['getContent'])->getMock();
        $response->expects($this->never())->method('getContent');

        return $response;
    }
}
