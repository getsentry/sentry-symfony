<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\RequestListenerRequestEvent;
use Sentry\SentryBundle\EventListener\RequestListenerResponseEvent;
use Sentry\SentryBundle\EventListener\TracingRequestListener;
use Sentry\State\HubInterface;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;

final class TracingRequestListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var TracingRequestListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new TracingRequestListener($this->hub);
    }

    /**
     * @dataProvider handleKernelRequestEventDataProvider
     */
    public function testHandleKernelRequestEvent(Options $options, Request $request, TransactionContext $expectedTransactionContext): void
    {
        ClockMock::withClockMock(1613493597.010275);

        $transaction = new Transaction(new TransactionContext());

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->once())
            ->method('getOptions')
            ->willReturn($options);

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->once())
            ->method('startTransaction')
            ->with($this->callback(function (TransactionContext $context) use ($expectedTransactionContext): bool {
                $this->assertEquals($context, $expectedTransactionContext);

                return true;
            }))
            ->willReturn($transaction);

        $this->hub->expects($this->once())
            ->method('setSpan')
            ->with($transaction);

        $this->listener->handleKernelRequestEvent(new RequestListenerRequestEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MASTER_REQUEST
        ));
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventDataProvider(): \Generator
    {
        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.server.REQUEST_TIME_FLOAT NOT EXISTS' => [
            new Options(['send_default_pii' => false]),
            Request::create('http://www.example.com'),
            $transactionContext,
        ];

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010819);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.server.REQUEST_TIME_FLOAT EXISTS' => [
            new Options(['send_default_pii' => false]),
            Request::create(
                'http://www.example.com',
                'GET',
                [],
                [],
                [],
                ['REQUEST_TIME_FLOAT' => 1613493597.010819]
            ),
            $transactionContext,
        ];

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://127.0.0.1/');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://127.0.0.1/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.ip' => '127.0.0.1',
        ]);

        yield 'request.server.HOST IS IPV4' => [
            new Options(['send_default_pii' => false]),
            Request::create('http://127.0.0.1'),
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/path');
        $request->attributes->set('_route', 'app_homepage');

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/path');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://www.example.com/path',
            'http.flavor' => '1.1',
            'route' => 'app_homepage',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes.route IS STRING' => [
            new Options(['send_default_pii' => false]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/path');
        $request->attributes->set('_route', new Route('/path'));

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/path');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://www.example.com/path',
            'http.flavor' => '1.1',
            'route' => '/path',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes.route IS INSTANCEOF Symfony\Component\Routing\Route' => [
            new Options(['send_default_pii' => false]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->attributes->set('_controller', 'App\\Controller::indexAction');

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => 'App\\Controller::indexAction',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes.controller IS STRING' => [
            new Options(['send_default_pii' => false]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->attributes->set('_controller', ['App\\Controller', 'indexAction']);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => 'App\\Controller::indexAction',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes.controller IS CALLABLE (1)' => [
            new Options(['send_default_pii' => false]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->attributes->set('_controller', [new class() {}, 'indexAction']);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => 'class@anonymous::indexAction',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes.controller IS CALLABLE (2)' => [
            new Options(['send_default_pii' => false]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->attributes->set('_controller', [10]);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
        ]);

        yield 'request.attributes.controller IS ARRAY and NOT VALID CALLABLE' => [
            new Options(['send_default_pii' => false]),
            $request,
            $transactionContext,
        ];

        $request = Request::create('http://www.example.com/');
        $request->attributes->set('_controller', [10]);

        $transactionContext = new TransactionContext();
        $transactionContext->setName('GET http://www.example.com/');
        $transactionContext->setOp('http.server');
        $transactionContext->setStartTimestamp(1613493597.010275);
        $transactionContext->setTags([
            'net.host.port' => '80',
            'http.method' => 'GET',
            'http.url' => 'http://www.example.com/',
            'http.flavor' => '1.1',
            'route' => '<unknown>',
            'net.host.name' => 'www.example.com',
            'net.peer.ip' => '127.0.0.1',
        ]);

        yield 'request.server.REMOTE_ADDR EXISTS and client.options.send_default_pii = TRUE' => [
            new Options(['send_default_pii' => true]),
            $request,
            $transactionContext,
        ];
    }

    public function testHandleKernelRequestEventDoesNothingIfRequestTypeIsSubRequest(): void
    {
        $this->hub->expects($this->never())
            ->method('startTransaction');

        $this->listener->handleKernelRequestEvent(new RequestListenerRequestEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::SUB_REQUEST
        ));
    }

    public function testHandleResponseRequestEvent(): void
    {
        $transaction = new Transaction(new TransactionContext());

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $this->listener->handleKernelResponseEvent(new RequestListenerResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MASTER_REQUEST,
            new Response()
        ));

        $this->assertSame(SpanStatus::ok(), $transaction->getStatus());
        $this->assertSame(['http.status_code' => '200'], $transaction->getTags());
    }

    public function testHandleResponseRequestEventDoesNothingIfNoTransactionIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn(null);

        $this->listener->handleKernelResponseEvent(new RequestListenerResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MASTER_REQUEST,
            new Response()
        ));
    }
}
