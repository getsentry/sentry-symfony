<?php

namespace Sentry\SentryBundle\Test\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Sentry\SentryBundle\Event\SentryUserContextEvent;
use Sentry\SentryBundle\EventListener\ExceptionListener;
use Sentry\SentryBundle\SentrySymfonyEvents;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
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
    /** @var ContainerBuilder|\PHPUnit_Framework_MockObject_MockObject */
    private $containerBuilder;

    /** @var \Raven_Client|\PHPUnit_Framework_MockObject_MockObject */
    private $mockSentryClient;

    /** @var TokenStorageInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $mockTokenStorage;

    /** @var AuthorizationCheckerInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $mockAuthorizationChecker;

    /** @var EventDispatcherInterface|\PHPUnit_Framework_MockObject_MockObject */
    private $mockEventDispatcher;

    public function setUp()
    {
        $this->mockTokenStorage = $this->createMock(TokenStorageInterface::class);
        $this->mockAuthorizationChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $this->mockSentryClient = $this->createMock(\Raven_Client::class);
        $this->mockEventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.root_dir', 'kernel/root');
        $containerBuilder->setParameter('kernel.environment', 'test');

        $containerBuilder->set('security.token_storage', $this->mockTokenStorage);
        $containerBuilder->set('security.authorization_checker', $this->mockAuthorizationChecker);
        $containerBuilder->set('sentry.client', $this->mockSentryClient);
        $containerBuilder->set('event_dispatcher', $this->mockEventDispatcher);

        $extension = new SentryExtension();
        $extension->load([], $containerBuilder);

        $this->containerBuilder = $containerBuilder;
    }

    public function test_that_it_is_an_instance_of_sentry_exception_listener()
    {
        $this->containerBuilder->compile();
        $listener = $this->containerBuilder->get('sentry.exception_listener');

        $this->assertInstanceOf(ExceptionListener::class, $listener);
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
        $listener = $this->containerBuilder->get('sentry.exception_listener');
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

        $listener = $this->containerBuilder->get('sentry.exception_listener');
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

        $listener = $this->containerBuilder->get('sentry.exception_listener');
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
        $listener = $this->containerBuilder->get('sentry.exception_listener');
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
        $listener = $this->containerBuilder->get('sentry.exception_listener');
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
        $listener = $this->containerBuilder->get('sentry.exception_listener');
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
        $listener = $this->containerBuilder->get('sentry.exception_listener');
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
        $listener = $this->containerBuilder->get('sentry.exception_listener');
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
        $listener = $this->containerBuilder->get('sentry.exception_listener');
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
        $listener = $this->containerBuilder->get('sentry.exception_listener');
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
        $listener = $this->containerBuilder->get('sentry.exception_listener');
        $listener->onKernelException($mockEvent);
    }

    /**
     * @dataProvider mockCommandProvider
     */
    public function test_that_it_captures_console_exception(Command $mockCommand = null, $expectedCommandName)
    {
        $reportableException = new \Exception();

        $mockEvent = $this->createMock(ConsoleErrorEvent::class);

        $mockEvent
            ->expects($this->once())
            ->method('getExitCode')
            ->willReturn(10);

        $mockEvent
            ->expects($this->once())
            ->method('getException')
            ->willReturn($reportableException);

        $mockEvent
            ->expects($this->once())
            ->method('getCommand')
            ->willReturn($mockCommand);

        $this->mockEventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo(SentrySymfonyEvents::PRE_CAPTURE), $this->identicalTo($mockEvent));

        $this->mockSentryClient
            ->expects($this->once())
            ->method('captureException')
            ->with(
                $this->identicalTo($reportableException),
                $this->identicalTo([
                    'tags' => [
                        'command' => $expectedCommandName,
                        'status_code' => 10,
                    ],
                ])
            );

        $this->containerBuilder->compile();
        $listener = $this->containerBuilder->get('sentry.exception_listener');
        $listener->onConsoleException($mockEvent);
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
        $replacementClient = $this->createMock('Raven_Client');

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
        $listener = $this->containerBuilder->get('sentry.exception_listener');

        $listener->setClient($replacementClient);

        $listener->onKernelException($mockEvent);
    }
}
