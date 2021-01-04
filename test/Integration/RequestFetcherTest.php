<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Test\Integration;

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

    /**
     * @dataProvider fetchRequestDataProvider
     */
    public function testFetchRequest(?Request $request, ?ServerRequestInterface $expectedRequest): void
    {
        $this->requestStack->expects($this->once())
            ->method('getCurrentRequest')
            ->willReturn($request);

        $this->httpMessageFactory->expects(null !== $expectedRequest ? $this->once() : $this->never())
            ->method('createRequest')
            ->with($request)
            ->willReturn($expectedRequest);

        $this->assertSame($expectedRequest, $this->requestFetcher->fetchRequest());
    }

    /**
     * @return \Generator<mixed>
     */
    public function fetchRequestDataProvider(): \Generator
    {
        yield [
            null,
            null,
        ];

        yield [
            Request::create('http://www.example.com'),
            new ServerRequest('GET', 'http://www.example.com'),
        ];
    }
}
