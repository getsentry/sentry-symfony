<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\TracingRequestListener;
use Sentry\SentryBundle\EventListener\TracingSubRequestListener;
use Sentry\State\Hub;
use Sentry\Tracing\SpanStatus;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\HeaderBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class HttpHeaderCollectionTest extends TestCase
{
    /**
     * @dataProvider legacyCollectionProvider
     *
     * @param array<string, mixed> $options
     */
    public function testLegacySpansDoNotCollectHeaders(array $options): void
    {
        $this->assertHeadersAreNotCollected($options, 2);
    }

    public function testUnsampledSpansDoNotReadHeaders(): void
    {
        $this->assertHeadersAreNotCollected(['data_collection' => [], 'traces_sample_rate' => 0.0], 0);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertHeadersAreNotCollected(array $options, int $expectedReads): void
    {
        $hub = $this->createHub($options);
        $request = Request::create('https://example.com');
        $request->headers = $this->getMockBuilder(HeaderBag::class)->setConstructorArgs([$request->headers->all()])->onlyMethods(['getIterator'])->getMock();
        $request->headers->expects($this->exactly($expectedReads))->method('getIterator')->willReturn(new \ArrayIterator(['x-test' => ['visible']]));
        $response = new Response();
        $response->headers = $this->createMock(ResponseHeaderBag::class);
        $response->headers->expects($this->exactly($expectedReads))->method('getIterator')->willReturn(new \ArrayIterator(['x-test' => ['visible']]));
        $response->headers->expects($this->exactly($expectedReads))->method('getCookies')->willReturn([]);
        $kernel = $this->createMock(HttpKernelInterface::class);
        foreach ([
            [new TracingRequestListener($hub), $this->mainRequestType()],
            [new TracingSubRequestListener($hub), HttpKernelInterface::SUB_REQUEST],
        ] as [$listener, $type]) {
            $listener->handleKernelRequestEvent(new RequestEvent($kernel, $request, $type));
            $listener->collectKernelResponseEvent(new ResponseEvent($kernel, $request, $type, $response));
            $span = $hub->getSpan();
            $this->assertNotNull($span);
            $this->assertArrayNotHasKey('http.request.header.x-test', $span->getData());
            $this->assertArrayNotHasKey('http.response.header.x-test', $span->getData());
        }
    }

    public function testDisabledResponseCookiesAreNotCollected(): void
    {
        $hub = $this->createHub(['data_collection' => ['cookies' => ['mode' => 'off']]]);
        $listener = new TracingRequestListener($hub);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://example.com');
        $listener->handleKernelRequestEvent(new RequestEvent($kernel, $request, $this->mainRequestType()));
        $response = new Response();
        $cookie = new class('session_id', 'secret') extends Cookie {
            public function __toString(): string
            {
                throw new \LogicException('Telemetry must not serialize cookies.');
            }
        };
        $response->headers->setCookie($cookie);
        $listener->collectKernelResponseEvent(new ResponseEvent($kernel, $request, $this->mainRequestType(), $response));
        $span = $hub->getSpan();
        $this->assertNotNull($span);
        $this->assertArrayNotHasKey('http.response.header.set-cookie', $span->getData());
        $this->assertArrayNotHasKey('http.response.header.set_cookie.session_id', $span->getData());
        $this->assertSame('secret', $cookie->getValue());
    }

    public function testResponseDataIsCollectedAfterStatus(): void
    {
        $hub = $this->createHub(['data_collection' => []]);
        $listener = new TracingRequestListener($hub);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://example.com');
        $listener->handleKernelRequestEvent(new RequestEvent($kernel, $request, $this->mainRequestType()));
        $response = new Response('', 200, ['X-Test' => 'controller']);
        $event = new ResponseEvent($kernel, $request, $this->mainRequestType(), $response);
        $listener->handleKernelResponseEvent($event);

        $span = $hub->getSpan();
        $this->assertNotNull($span);
        $this->assertSame(SpanStatus::ok(), $span->getStatus());
        $this->assertArrayNotHasKey('http.response.header.x-test', $span->getData());

        $response->headers->set('X-Test', 'prepared');
        $listener->collectKernelResponseEvent($event);

        $this->assertSame(['prepared'], $span->getData()['http.response.header.x-test']);
    }

    public function testParsedResponseCookiesDoNotSerializeHeaders(): void
    {
        $hub = $this->createHub(['data_collection' => ['http_headers' => ['mode' => 'off']]]);
        $listener = new TracingRequestListener($hub);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('https://example.com');
        $listener->handleKernelRequestEvent(new RequestEvent($kernel, $request, $this->mainRequestType()));
        $response = new Response();
        $response->headers->setCookie(new class('session_id', 'secret') extends Cookie {
            public function __toString(): string
            {
                throw new \LogicException('Telemetry must not serialize cookies.');
            }
        });
        $response->headers->setCookie(new Cookie('theme', 'dark', 0, '/'));
        $response->headers->setCookie(new Cookie('theme', 'light', 0, '/other'));
        $response->headers->setCookie(new Cookie('locale', null, 0, '/'));
        $response->headers->setCookie(new Cookie('locale', 'en', 0, '/other'));
        $listener->collectKernelResponseEvent(new ResponseEvent($kernel, $request, $this->mainRequestType(), $response));
        $span = $hub->getSpan();
        $this->assertNotNull($span);
        $this->assertSame('[Filtered]', $span->getData()['http.response.header.set_cookie.session_id']);
        $this->assertSame(['dark', 'light'], $span->getData()['http.response.header.set_cookie.theme']);
        $this->assertSame([null, 'en'], $span->getData()['http.response.header.set_cookie.locale']);
        $this->assertArrayNotHasKey('http.response.header.set-cookie', $span->getData());
    }

    /**
     * @return \Generator<mixed>
     */
    public function legacyCollectionProvider(): \Generator
    {
        yield 'legacy pii off' => [['send_default_pii' => false]];
        yield 'legacy pii on' => [['send_default_pii' => true]];
        yield 'null collection pii off' => [['data_collection' => null, 'send_default_pii' => false]];
        yield 'null collection pii on' => [['data_collection' => null, 'send_default_pii' => true]];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createHub(array $options): Hub
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturn(new Options($options + ['traces_sample_rate' => 1.0]));

        return new Hub($client);
    }

    private function mainRequestType(): int
    {
        return (int) \constant(HttpKernelInterface::class . '::' . (\defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? 'MAIN_REQUEST' : 'MASTER_REQUEST'));
    }
}
