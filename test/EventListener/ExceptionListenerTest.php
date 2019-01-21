<?php

namespace Sentry\SentryBundle\Test\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\Event\SentryUserContextEvent;
use Sentry\SentryBundle\EventListener\RequestListener;
use Sentry\SentryBundle\EventListener\SentryExceptionListenerInterface;
use Sentry\SentryBundle\SentrySymfonyEvents;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;
use Symfony\Component\Security\Core\User\UserInterface;

class ExceptionListenerTest extends TestCase
{
    private const LISTENER_TEST_PUBLIC_ALIAS = 'sentry.exception_listener.public_alias';

    private $containerBuilder;

    private $mockSentryClient;

    private $mockTokenStorage;

    private $mockAuthorizationChecker;

    private $mockEventDispatcher;

    /**
     * @var RequestStack
     */
    private $requestStack;

    public function setUp()
    {
        $this->mockTokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->mockAuthorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->mockSentryClient = $this->createMock(\Raven_Client::class);
        $this->mockEventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->requestStack = new RequestStack();

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.root_dir', 'kernel/root');
        $containerBuilder->setParameter('kernel.environment', 'test');

        $containerBuilder->set('request_stack', $this->requestStack);
        $containerBuilder->set('security.token_storage', $this->mockTokenStorage);
        $containerBuilder->set('security.authorization_checker', $this->mockAuthorizationChecker);
        $containerBuilder->set('sentry.client', $this->mockSentryClient);
        $containerBuilder->set('event_dispatcher', $this->mockEventDispatcher);
        $containerBuilder->setAlias(self::LISTENER_TEST_PUBLIC_ALIAS, new Alias('sentry.exception_listener', true));

        $extension = new SentryExtension();
        $extension->load([], $containerBuilder);

        $this->containerBuilder = $containerBuilder;
    }

    public function test_that_it_is_an_instance_of_sentry_exception_listener()
    {
        $this->containerBuilder->compile();
        $listener = $this->getListener();

        $this->assertInstanceOf(RequestListener::class, $listener);
    }

    public function test_that_user_data_is_not_set_on_subrequest()
    {
        $mockEvent = $this->createMock(GetResponseEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::SUB_REQUEST);

        $this->mockSentryClient
            ->expects($this->never())
            ->method('set_user_data')
            ->withAnyParameters();

        $this->mockEventDispatcher
            ->expects($this->never())
            ->method('dispatch')
            ->withAnyParameters();

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
    }

    public function test_that_user_data_is_not_set_if_token_storage_not_present()
    {
        $this->containerBuilder->set('security.token_storage', null);

        $mockEvent = $this->createMock(GetResponseEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::MASTER_REQUEST);

        $this->mockSentryClient
            ->expects($this->never())
            ->method('set_user_data')
            ->withAnyParameters();

        $this->mockEventDispatcher
            ->expects($this->never())
            ->method('dispatch')
            ->withAnyParameters();

        $this->assertFalse($this->containerBuilder->has('security.token_storage'));

        $this->containerBuilder->compile();

        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
    }

    public function test_that_user_data_is_not_set_if_authorization_checker_not_present()
    {
        $this->containerBuilder->set('security.authorization_checker', null);

        $mockEvent = $this->createMock(GetResponseEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::MASTER_REQUEST);

        $this->mockSentryClient
            ->expects($this->never())
            ->method('set_user_data')
            ->withAnyParameters();

        $this->mockEventDispatcher
            ->expects($this->never())
            ->method('dispatch')
            ->withAnyParameters();

        $this->containerBuilder->compile();

        $this->assertFalse($this->containerBuilder->has('security.authorization_checker'));

        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
    }

    public function test_that_user_data_is_not_set_if_token_not_present()
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUsername')
            ->willReturn('username');

        $mockEvent = $this->createMock(GetResponseEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::MASTER_REQUEST);

        $this->mockAuthorizationChecker
            ->method('isGranted')
            ->with($this->identicalTo(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED))
            ->willReturn(true);

        $this->mockTokenStorage
            ->method('getToken')
            ->willReturn(null);

        $this->mockSentryClient
            ->expects($this->never())
            ->method('set_user_data')
            ->withAnyParameters();

        $this->mockEventDispatcher
            ->expects($this->never())
            ->method('dispatch')
            ->withAnyParameters();

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
    }

    public function test_that_user_data_is_not_set_if_not_authorized()
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUsername')->willReturn('username');

        $mockToken = $this->createMock(TokenInterface::class);

        $mockToken
            ->method('getUser')
            ->willReturn($user);

        $mockEvent = $this->createMock(GetResponseEvent::class);

        $mockEvent->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::MASTER_REQUEST);

        $this->mockAuthorizationChecker
            ->method('isGranted')
            ->with($this->identicalTo(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED))
            ->willReturn(false);

        $this->mockTokenStorage
            ->method('getToken')
            ->willReturn($mockToken);

        $this->mockSentryClient
            ->expects($this->never())
            ->method('set_user_data')
            ->withAnyParameters();

        $this->mockEventDispatcher
            ->expects($this->never())
            ->method('dispatch')
            ->withAnyParameters();

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
    }

    public function test_that_username_is_set_from_user_interface_if_token_present_and_user_set_as_user_interface()
    {
        $user = $this->createMock(UserInterface::class);
        $user->method('getUsername')->willReturn('username');

        $mockToken = $this->createMock(TokenInterface::class);

        $mockToken
            ->method('getUser')
            ->willReturn($user);

        $mockToken
            ->method('isAuthenticated')
            ->willReturn(true);

        $mockEvent = $this->createMock(GetResponseEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::MASTER_REQUEST);

        $this
            ->mockAuthorizationChecker
            ->method('isGranted')
            ->with($this->identicalTo(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED))
            ->willReturn(true);

        $this->mockTokenStorage
            ->method('getToken')
            ->willReturn($mockToken);

        $this
            ->mockSentryClient
            ->expects($this->once())
            ->method('set_user_data')
            ->with($this->identicalTo('username'));

        $this
            ->mockEventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->identicalTo(SentrySymfonyEvents::SET_USER_CONTEXT),
                $this->isInstanceOf(SentryUserContextEvent::class)
            );

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
    }

    public function test_that_username_is_set_from_user_interface_if_token_present_and_user_set_as_string()
    {
        $mockToken = $this->createMock(TokenInterface::class);

        $mockToken
            ->method('getUser')
            ->willReturn('some_user');

        $mockToken
            ->method('isAuthenticated')
            ->willReturn(true);

        $mockEvent = $this->createMock(GetResponseEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::MASTER_REQUEST);

        $this->mockAuthorizationChecker
            ->method('isGranted')
            ->with($this->identicalTo(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED))
            ->willReturn(true);

        $this->mockTokenStorage
            ->method('getToken')
            ->willReturn($mockToken);

        $this->mockSentryClient
            ->expects($this->once())
            ->method('set_user_data')
            ->with($this->identicalTo('some_user'));

        $this->mockEventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->identicalTo(SentrySymfonyEvents::SET_USER_CONTEXT),
                $this->isInstanceOf(SentryUserContextEvent::class)
            );

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
    }

    public function test_that_username_is_set_from_user_interface_if_token_present_and_user_set_object_with_to_string()
    {
        $mockUser = $this->getMockBuilder('stdClass')
            ->setMethods(['__toString'])
            ->getMock();

        $mockUser
            ->expects($this->once())
            ->method('__toString')
            ->willReturn('std_user');

        $mockToken = $this->createMock(TokenInterface::class);

        $mockToken
            ->method('getUser')
            ->willReturn($mockUser);

        $mockToken
            ->method('isAuthenticated')
            ->willReturn(true);

        $mockEvent = $this->createMock(GetResponseEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::MASTER_REQUEST);

        $this->mockAuthorizationChecker
            ->method('isGranted')
            ->with($this->identicalTo(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED))
            ->willReturn(true);

        $this->mockTokenStorage
            ->method('getToken')
            ->willReturn($mockToken);

        $this->mockSentryClient
            ->expects($this->once())
            ->method('set_user_data')
            ->with($this->identicalTo('std_user'));

        $this->mockEventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->identicalTo(SentrySymfonyEvents::SET_USER_CONTEXT),
                $this->isInstanceOf(SentryUserContextEvent::class)
            );

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
    }

    public function test_that_ip_is_set_from_request_stack()
    {
        $mockToken = $this->createMock(TokenInterface::class);

        $mockToken
            ->method('getUser')
            ->willReturn('some_user');

        $mockToken
            ->method('isAuthenticated')
            ->willReturn(true);

        $mockEvent = $this->createMock(GetResponseEvent::class);

        $this->requestStack->push(new Request([], [], [], [], [], [
            'REMOTE_ADDR' => '1.2.3.4',
        ]));

        $mockEvent
            ->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::MASTER_REQUEST);

        $this->mockAuthorizationChecker
            ->method('isGranted')
            ->with($this->identicalTo(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED))
            ->willReturn(true);

        $this->mockTokenStorage
            ->method('getToken')
            ->willReturn($mockToken);

        $this->mockSentryClient
            ->expects($this->once())
            ->method('set_user_data')
            ->with($this->identicalTo('some_user'), null, ['ip_address' => '1.2.3.4']);

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
    }

    public function test_regression_with_unauthenticated_user_token_PR_78()
    {
        $mockToken = $this->createMock(TokenInterface::class);
        $mockToken
            ->method('isAuthenticated')
            ->willReturn(false);

        $mockEvent = $this->createMock(GetResponseEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getRequestType')
            ->willReturn(HttpKernelInterface::MASTER_REQUEST);

        $this->mockTokenStorage
            ->method('getToken')
            ->willReturn($mockToken);

        $this->containerBuilder->compile();
        $listener = $this->getListener();
        $listener->onKernelRequest($mockEvent);
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
