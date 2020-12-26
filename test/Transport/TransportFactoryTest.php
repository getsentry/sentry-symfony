<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Test\Transport;

use GuzzleHttp\Promise\RejectionException;
use Http\Client\Exception\NetworkException;
use Http\Client\HttpAsyncClient as HttpAsyncClientInterface;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Promise\Promise as HttpPromiseInterface;
use Http\Promise\RejectedPromise;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\Response;
use Sentry\ResponseStatus;
use Sentry\SentryBundle\Transport\TransportFactory;
use Sentry\Transport\HttpTransport;

final class TransportFactoryTest extends TestCase
{
    public function testCreate(): void
    {
        $transport = (new TransportFactory())->create(new Options(['dsn' => 'http://public@example.com/sentry/1']));

        $this->assertInstanceOf(HttpTransport::class, $transport);

        try {
            $transport->send(Event::createEvent())->wait();

            $this->fail('Failed asserting that the transport returns a rejected promise on error.');
        } catch (RejectionException $exception) {
            $this->assertInstanceOf(Response::class, $exception->getReason());
        }
    }

    public function testCreateWithCustomFactories(): void
    {
        $uriFactory = $this->createMock(UriFactoryInterface::class);
        $requestFactory = $this->createMock(RequestFactoryInterface::class);
        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $httpClient = $this->createMock(HttpAsyncClientInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $transportFactory = new TransportFactory(
            $uriFactory,
            $requestFactory,
            $responseFactory,
            $streamFactory,
            $httpClient,
            $logger
        );

        $requestFactory->expects($this->once())
            ->method('createRequest')
            ->willReturnCallback(static function (...$arguments): RequestInterface {
                return Psr17FactoryDiscovery::findRequestFactory()->createRequest(...$arguments);
            });

        $streamFactory->expects($this->atLeastOnce())
            ->method('createStream')
            ->willReturnCallback(static function (...$arguments): StreamInterface {
                return Psr17FactoryDiscovery::findStreamFactory()->createStream(...$arguments);
            });

        $httpClient->expects($this->once())
            ->method('sendAsyncRequest')
            ->willReturnCallback(static function (RequestInterface $request): HttpPromiseInterface {
                return new RejectedPromise(new NetworkException('foo', $request));
            });

        $logger->expects($this->once())
            ->method('error')
            ->withAnyParameters();

        $event = Event::createEvent();
        $transport = $transportFactory->create(new Options(['dsn' => 'http://public@example.com/sentry/1', 'send_attempts' => 0]));

        try {
            $transport->send($event)->wait();

            $this->fail('Failed asserting that the transport returns a rejected promise on error.');
        } catch (RejectionException $exception) {
            /** @var Response $response */
            $response = $exception->getReason();

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(ResponseStatus::failed(), $response->getStatus());
            $this->assertSame($event, $response->getEvent());
        }
    }
}
