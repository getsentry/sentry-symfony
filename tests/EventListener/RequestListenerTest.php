<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestListenerTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var RequestListener
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new RequestListener($this->hub);
    }

    /**
     * @dataProvider handleKernelRequestEventDataProvider
     */
    public function testHandleKernelRequestEvent(RequestEvent $requestEvent, ClientInterface $client, UserDataBag $currentUser, UserDataBag $expectedUser): void
    {
        $scope = new Scope();
        $scope->setUser($currentUser);

        $this->hub->expects($this->any())
            ->method('getClient')
            ->willReturn($client);

        $this->hub->expects($this->any())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->listener->handleKernelRequestEvent($requestEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertEquals($expectedUser, $event->getUser());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelRequestEventDataProvider(): \Generator
    {
        yield 'event.requestType != MASTER_REQUEST' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                HttpKernelInterface::SUB_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new UserDataBag(),
            new UserDataBag(),
        ];

        yield 'options.send_default_pii = FALSE' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => false])),
            new UserDataBag(),
            new UserDataBag(),
        ];

        yield 'request.clientIp IS NULL' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request(),
                \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new UserDataBag(),
            new UserDataBag(),
        ];

        yield 'user.ipAddress IS NULL && request.clientIp IS NOT NULL' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new UserDataBag('foo_user'),
            new UserDataBag('foo_user', null, '127.0.0.1'),
        ];

        yield 'user.ipAddress IS NOT NULL && request.clientIp IS NOT NULL' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '127.0.0.1']),
                \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new UserDataBag('foo_user', null, '::1'),
            new UserDataBag('foo_user', null, '::1'),
        ];

        yield 'remote address empty' => [
            new RequestEvent(
                $this->createMock(HttpKernelInterface::class),
                new Request([], [], [], [], [], ['REMOTE_ADDR' => '']),
                \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
            ),
            $this->getMockedClientWithOptions(new Options(['send_default_pii' => true])),
            new UserDataBag(),
            new UserDataBag(),
        ];
    }

    /**
     * @dataProvider handleKernelControllerEventDataProvider
     *
     * @param array<string, string> $expectedTags
     */
    public function testHandleKernelControllerEvent(ControllerEvent $controllerEvent, array $expectedTags): void
    {
        $scope = new Scope();

        $this->hub->expects($this->any())
            ->method('configureScope')
            ->willReturnCallback(static function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $this->listener->handleKernelControllerEvent($controllerEvent);

        $event = $scope->applyToEvent(Event::createEvent());

        $this->assertNotNull($event);
        $this->assertSame($expectedTags, $event->getTags());
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleKernelControllerEventDataProvider(): \Generator
    {
        yield 'event.requestType != MASTER_REQUEST' => [
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request([], [], ['_route' => 'homepage']),
                HttpKernelInterface::SUB_REQUEST
            ),
            [],
        ];

        yield 'event.requestType = MASTER_REQUEST && request.attributes._route NOT EXISTS ' => [
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request(),
                \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
            ),
            [],
        ];

        yield 'event.requestType = MASTER_REQUEST && request.attributes._route EXISTS' => [
            new ControllerEvent(
                $this->createMock(HttpKernelInterface::class),
                static function () {
                },
                new Request([], [], ['_route' => 'homepage']),
                \defined(HttpKernelInterface::class . '::MAIN_REQUEST') ? HttpKernelInterface::MAIN_REQUEST : HttpKernelInterface::MASTER_REQUEST
            ),
            [
                'route' => 'homepage',
            ],
        ];
    }

    private function getMockedClientWithOptions(Options $options): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->any())
            ->method('getOptions')
            ->willReturn($options);

        return $client;
    }
}
