<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\SentryBundle\Integration\RequestFetcher;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RequestFetcherTest extends TestCase
{
    /**
     * @var RequestStack&MockObject
     */
    private $requestStack;

    /**
     * @var HttpMessageFactoryInterface&MockObject
     */
    private $httpMessageFactory;

    /**
     * @var RequestFetcher
     */
    private $requestFetcher;

    protected function setUp(): void
    {
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->httpMessageFactory = $this->createMock(HttpMessageFactoryInterface::class);
        $this->requestFetcher = new RequestFetcher($this->requestStack, $this->httpMessageFactory);
    }

    public function testFetchRequest(): void
    {
        $request = Request::create('https://www.example.com');
        $expectedRequest = $this->createMock(ServerRequestInterface::class);

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn($request);

        $this->httpMessageFactory->expects($this->once())
            ->method('createRequest')
            ->with($request)
            ->willReturn($expectedRequest);

        $this->assertSame($expectedRequest, $this->requestFetcher->fetchRequest());
    }

    public function testFetchRequestReturnsNullIfTheRequestStackIsEmpty(): void
    {
        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(null);

        $this->httpMessageFactory->expects($this->never())
            ->method('createRequest');

        $this->assertNull($this->requestFetcher->fetchRequest());
    }

    public function testFetchRequestReturnsNullIfTheRequestFactoryThrowsAnException(): void
    {
        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn(new Request());

        $this->httpMessageFactory->expects($this->once())
            ->method('createRequest')
            ->willThrowException(new \Exception());

        $this->assertNull($this->requestFetcher->fetchRequest());
    }

    /**
     * @param array<string, mixed>|null $parsedBody
     * @param array<string, mixed>|null $expectedParsedBody
     *
     * @dataProvider fetchRequestParsedBodyDataProvider
     */
    public function testFetchRequestOnlyKeepsAnEmptyParsedBodyForForms(?string $contentType, ?array $parsedBody, ?array $expectedParsedBody): void
    {
        // Symfony sets a form content type for POST requests without one
        $request = null === $contentType
            ? Request::create('https://www.example.com')
            : Request::create('https://www.example.com', 'POST', [], [], [], ['CONTENT_TYPE' => $contentType], 'foo');

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn($request);

        $this->httpMessageFactory->expects($this->once())
            ->method('createRequest')
            ->with($request)
            ->willReturn((new ServerRequest('POST', 'https://www.example.com'))->withParsedBody($parsedBody));

        $serverRequest = $this->requestFetcher->fetchRequest();

        $this->assertNotNull($serverRequest);
        $this->assertSame($expectedParsedBody, $serverRequest->getParsedBody());
    }

    /**
     * @return \Generator<mixed>
     */
    public function fetchRequestParsedBodyDataProvider(): \Generator
    {
        yield 'For a request without a content type, the empty parsed body is dropped' => [
            null,
            [],
            null,
        ];

        yield 'For a body that is not parsed by Symfony, the empty parsed body is dropped' => [
            'text/plain',
            [],
            null,
        ];

        yield 'For a JSON body not parsed by the bridge, the empty parsed body is dropped' => [
            'application/json',
            [],
            null,
        ];

        yield 'For a JSON body parsed by the bridge, the parsed body is kept' => [
            'application/json',
            ['foo' => 'bar'],
            ['foo' => 'bar'],
        ];

        yield 'For a URL encoded form, the empty parsed body is kept' => [
            'Application/X-WWW-Form-Urlencoded; charset=UTF-8',
            [],
            [],
        ];

        yield 'For a multipart form, the empty parsed body is kept' => [
            'multipart/form-data; boundary=foo',
            [],
            [],
        ];

        yield 'A missing parsed body is kept' => [
            'text/plain',
            null,
            null,
        ];
    }

    public function testFetchRequestUsesManuallySetRequestBeforeRequestStack(): void
    {
        $manualRequest = Request::create('https://www.example.com/manual');
        $expectedRequest = $this->createMock(ServerRequestInterface::class);

        $this->requestFetcher->setRequest($manualRequest);

        $this->requestStack->expects($this->never())
            ->method('getCurrentRequest');

        $this->httpMessageFactory->expects($this->once())
            ->method('createRequest')
            ->with($manualRequest)
            ->willReturn($expectedRequest);

        $this->assertSame($expectedRequest, $this->requestFetcher->fetchRequest());
    }

    public function testResetClearsTheManuallySetRequest(): void
    {
        $manualRequest = Request::create('https://www.example.com/manual');
        $stackRequest = Request::create('https://www.example.com/stack');
        $expectedRequest = $this->createMock(ServerRequestInterface::class);

        $this->requestFetcher->setRequest($manualRequest);
        $this->requestFetcher->reset();

        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn($stackRequest);

        $this->httpMessageFactory->expects($this->once())
            ->method('createRequest')
            ->with($stackRequest)
            ->willReturn($expectedRequest);

        $this->assertSame($expectedRequest, $this->requestFetcher->fetchRequest());
    }
}
