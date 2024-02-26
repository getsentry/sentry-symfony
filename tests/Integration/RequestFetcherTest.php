<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Integration;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcher;
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
}
