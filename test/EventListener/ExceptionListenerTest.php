<?php

namespace Sentry\SentryBundle\Test\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\EventListener\RequestListener;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class ExceptionListenerTest extends TestCase
{
    private const LISTENER_TEST_PUBLIC_ALIAS = 'sentry.exception_listener.public_alias';

    private $containerBuilder;

    private $mockTokenStorage;

    private $mockAuthorizationChecker;

    private $mockEventDispatcher;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function setUp()
    {
        $this->markTestSkipped('To be refactored');
        $this->mockTokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->mockAuthorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->mockEventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->requestStack = new RequestStack();

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.root_dir', 'kernel/root');
        $containerBuilder->setParameter('kernel.environment', 'test');

        $containerBuilder->set('request_stack', $this->requestStack);
        $containerBuilder->set('security.token_storage', $this->mockTokenStorage);
        $containerBuilder->set('security.authorization_checker', $this->mockAuthorizationChecker);
        $containerBuilder->set('event_dispatcher', $this->mockEventDispatcher);
        $containerBuilder->setAlias(self::LISTENER_TEST_PUBLIC_ALIAS, new Alias('sentry.exception_listener', true));

        $extension = new SentryExtension();
        $extension->load([], $containerBuilder);

        $this->containerBuilder = $containerBuilder;
    }

    public function test_that_it_does_not_report_http_exception_if_included_in_capture_skip()
    {
        $mockEvent = $this->createMock(GetResponseForExceptionEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getException')
            ->willReturn(new HttpException(401));

        $this->mockEventDispatcher
            ->expects($this->never())
            ->method('dispatch')
            ->withAnyParameters();

        $this->mockSentryClient
            ->expects($this->never())
            ->method('captureException')
            ->withAnyParameters();

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelException($mockEvent);
    }

    public function test_that_it_captures_exception()
    {
        $reportableException = new \Exception();

        $mockEvent = $this->createMock(GetResponseForExceptionEvent::class);
        $mockEvent
            ->expects($this->once())
            ->method('getException')
            ->willReturn($reportableException);

        $this->mockEventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo(SentrySymfonyEvents::PRE_CAPTURE), $this->identicalTo($mockEvent));

        $this->mockSentryClient
            ->expects($this->once())
            ->method('captureException')
            ->with($this->identicalTo($reportableException));

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelException($mockEvent);
    }

    public function test_that_it_captures_exception_with_route()
    {
        $reportableException = new \Exception();

        $mockEvent = $this->createMock(GetResponseForExceptionEvent::class);
        $mockEvent
            ->expects($this->once())
            ->method('getException')
            ->willReturn($reportableException);

        $this->mockEventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo(SentrySymfonyEvents::PRE_CAPTURE), $this->identicalTo($mockEvent));

        $data = [
            'tags' => [
                'route' => 'homepage',
            ],
        ];

        $this->requestStack->push(new Request([], [], ['_route' => 'homepage']));

        $this->mockSentryClient
            ->expects($this->once())
            ->method('captureException')
            ->with($this->identicalTo($reportableException), $data);

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelException($mockEvent);
    }

    /**
     * @dataProvider mockCommandProvider
     */
    public function test_that_it_captures_console_exception(?Command $mockCommand, string $expectedCommandName)
    {
        if (! class_exists('Symfony\Component\Console\Event\ConsoleExceptionEvent')) {
            $this->markTestSkipped('ConsoleExceptionEvent does not exist anymore on Symfony 4');
        }

        if (null === $mockCommand) {
            $this->markTestSkipped('Command missing is not possibile with ConsoleExceptionEvent');
        }

        $exception = $this->createMock(\Exception::class);
        /** @var InputInterface $input */
        $input = $this->createMock(InputInterface::class);
        /** @var OutputInterface $output */
        $output = $this->createMock(OutputInterface::class);

        $event = new ConsoleExceptionEvent($mockCommand, $input, $output, $exception, 10);

        $this->mockEventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo(SentrySymfonyEvents::PRE_CAPTURE), $this->identicalTo($event));

        $this->mockSentryClient
            ->expects($this->once())
            ->method('captureException')
            ->with(
                $this->identicalTo($exception),
                $this->identicalTo([
                    'tags' => [
                        'command' => $expectedCommandName,
                        'status_code' => 10,
                    ],
                ])
            );

        $this->containerBuilder->compile();
        /** @var SentryExceptionListenerInterface $listener */
        $listener = $this->getListener();
        $listener->onConsoleException($event);
    }

    /**
     * @dataProvider mockCommandProvider
     */
    public function test_that_it_captures_console_error(?Command $mockCommand, string $expectedCommandName)
    {
        $error = $this->createMock(\Error::class);
        /** @var InputInterface $input */
        $input = $this->createMock(InputInterface::class);
        /** @var OutputInterface $output */
        $output = $this->createMock(OutputInterface::class);

        $event = new ConsoleErrorEvent($input, $output, $error, $mockCommand);
        $event->setExitCode(10);

        $this->mockEventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo(SentrySymfonyEvents::PRE_CAPTURE), $this->identicalTo($event));

        $this->mockSentryClient
            ->expects($this->once())
            ->method('captureException')
            ->with(
                $this->identicalTo($error),
                $this->identicalTo([
                    'tags' => [
                        'command' => $expectedCommandName,
                        'status_code' => 10,
                    ],
                ])
            );

        $this->containerBuilder->compile();
        /** @var SentryExceptionListenerInterface $listener */
        $listener = $this->getListener();
        $listener->onConsoleError($event);
    }

    public function mockCommandProvider()
    {
        $mockCommand = $this->createMock(Command::class);
        $mockCommand
            ->expects($this->once())
            ->method('getName')
            ->willReturn('cmd name');

        return [
            [$mockCommand, 'cmd name'],
            [null, 'N/A'], // the error may have been triggered before the command is loaded
        ];
    }

    public function test_that_it_can_replace_client()
    {
        $replacementClient = $this->createMock(\Raven_Client::class);

        $reportableException = new \Exception();

        $mockEvent = $this->createMock(GetResponseForExceptionEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getException')
            ->willReturn($reportableException);

        $this->mockEventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo(SentrySymfonyEvents::PRE_CAPTURE), $this->identicalTo($mockEvent));

        $this->mockSentryClient
            ->expects($this->never())
            ->method('captureException')
            ->withAnyParameters();

        $replacementClient
            ->expects($this->once())
            ->method('captureException')
            ->with($this->identicalTo($reportableException));

        $this->containerBuilder->compile();
        /** @var RequestListener $listener */
        $listener = $this->getListener();
        $this->assertInstanceOf(RequestListener::class, $listener);

        $listener->setClient($replacementClient);

        $listener->onKernelException($mockEvent);
    }

    private function getListener(): SentryExceptionListenerInterface
    {
        return $this->containerBuilder->get(self::LISTENER_TEST_PUBLIC_ALIAS);
    }
}
