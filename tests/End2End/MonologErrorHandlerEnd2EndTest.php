<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Monolog\Logger;
use Sentry\Event;
use Sentry\SentryBundle\Monolog\PhpErrorHandler;
use Sentry\SentryBundle\Monolog\UncaughtExceptionHandler;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithMonologAndListeners;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithMonologErrors;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithMonologErrorsAndExceptionLogChannel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @runTestsInSeparateProcesses
 */
final class MonologErrorHandlerEnd2EndTest extends WebTestCase
{
    /**
     * @var class-string<KernelInterface>
     */
    private static $kernelClass = KernelWithMonologErrors::class;

    protected static function getKernelClass(): string
    {
        return self::$kernelClass;
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::$kernelClass = KernelWithMonologErrors::class;
        StubTransport::$events = [];

        // Like symfony/runtime does in real applications: otherwise FrameworkBundle doesn't replace PHPUnit's handler.
        // Without a bootstrapping logger, nothing is printed when no logger gets configured (e.g. when the SDK's error
        // handler is registered on top of it)
        \Symfony\Component\ErrorHandler\ErrorHandler::register(new \Symfony\Component\ErrorHandler\ErrorHandler(null, false));
        file_put_contents(End2EndTest::SENT_EVENTS_LOG, '');
    }

    public function testHandlersArePushedFirstToTheErrorChannelsOnly(): void
    {
        $container = $this->getTestContainer();

        $this->assertInstanceOf(PhpErrorHandler::class, $this->getLogger($container, 'monolog.logger.php')->getHandlers()[0]);

        $channels = ['monolog.logger.request', 'monolog.logger.console'];

        if (interface_exists(MessageBusInterface::class)) {
            $channels[] = 'monolog.logger.messenger';
        }

        foreach ($channels as $id) {
            $this->assertInstanceOf(UncaughtExceptionHandler::class, $this->getLogger($container, $id)->getHandlers()[0], $id);
        }

        foreach (['monolog.logger', 'monolog.logger.event', 'monolog.logger.cache'] as $id) {
            if (!$container->has($id)) {
                continue;
            }

            foreach ($this->getLogger($container, $id)->getHandlers() as $handler) {
                $this->assertNotInstanceOf(PhpErrorHandler::class, $handler, $id);
                $this->assertNotInstanceOf(UncaughtExceptionHandler::class, $handler, $id);
            }
        }
    }

    public function testExceptionHandledAndLoggedByTheApplicationIsNotCaptured(): void
    {
        $container = $this->getTestContainer();

        $this->getLogger($container, 'monolog.logger')->error('Payment failed', ['exception' => new \RuntimeException()]);

        $this->assertCapturedExceptions([]);
    }

    public function testUncaughtException(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/exception');

        $this->assertSame(500, $client->getResponse()->getStatusCode());
        $this->assertCapturedExceptions([\RuntimeException::class]);
        $event = StubTransport::$events[0];
        $this->assertSame('request', $event->getExtra()['monolog.channel']);
    }

    public function testNotFound(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/missing-page');

        $this->assertSame(404, $client->getResponse()->getStatusCode());
        $this->assertCapturedExceptions([\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class]);
    }

    public function testIgnoredExceptionStillIgnored(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/bad-request');

        $this->assertSame(400, $client->getResponse()->getStatusCode());
        $this->assertCapturedExceptions([]);
    }

    public function testSilencedNotice(): void
    {
        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/notice');

        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertCapturedExceptions([\Sentry\Exception\SilencedErrorException::class]);
    }

    public function testSilencedNoticeDoesNotReachTheLogsWhenSymfonyDoesNotScream(): void
    {
        $client = static::createClient(['debug' => false]);
        $logFile = $client->getKernel()->getLogDir() . '/' . $client->getKernel()->getEnvironment() . '.log';
        file_put_contents($logFile, '');

        $client->request('GET', '/notice');

        $this->assertCapturedExceptions([\Sentry\Exception\SilencedErrorException::class]);
        $this->assertStringNotContainsString('This is an intentional notice', (string) file_get_contents($logFile));
    }

    public function testCommand(): void
    {
        $application = new Application(self::bootKernel());

        try {
            $application->doRun(new ArgvInput(['bin/console', 'main-command', '--option1', '--option2=foo', 'bar']), new NullOutput());
        } catch (\RuntimeException $e) {
        }

        $this->assertCapturedExceptions([\RuntimeException::class]);
        $this->assertSame('console', StubTransport::$events[0]->getExtra()['monolog.channel']);
        $this->assertSame('main-command --option1 --option2=foo bar', StubTransport::$events[0]->getExtra()['Full command'] ?? null);
    }

    public function testMessengerHardFailure(): void
    {
        $this->skipIfMessengerIsMissing();

        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/dispatch-unrecoverable-message');
        $this->consumeOneMessage($client->getKernel());

        $this->assertCount(1, StubTransport::$events, $this->describeEvents());
        $this->assertSame('messenger', StubTransport::$events[0]->getExtra()['monolog.channel']);
    }

    public function testMessengerSoftFailureIsNotCaptured(): void
    {
        $this->skipIfMessengerIsMissing();

        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/dispatch-message');
        $this->consumeOneMessage($client->getKernel());

        $this->assertCount(0, StubTransport::$events, $this->describeEvents());
    }

    public function testUncaughtExceptionLoggedToCustomChannel(): void
    {
        if (Kernel::VERSION_ID < 70300) {
            $this->markTestSkipped('The "framework.exceptions.*.log_channel" option requires Symfony >= 7.3.');
        }

        self::$kernelClass = KernelWithMonologErrorsAndExceptionLogChannel::class;

        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/exception');

        $this->assertCapturedExceptions([\RuntimeException::class]);
        $this->assertSame('errors', StubTransport::$events[0]->getExtra()['monolog.channel']);
    }

    public function testDuplicatesWhenListenersStayEnabled(): void
    {
        self::$kernelClass = KernelWithMonologAndListeners::class;

        $client = static::createClient(['debug' => false]);
        $client->request('GET', '/exception');

        $this->assertCapturedExceptions([\RuntimeException::class, \RuntimeException::class]);
    }

    private function skipIfMessengerIsMissing(): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            $this->markTestSkipped('Messenger missing');
        }
    }

    private function consumeOneMessage(KernelInterface $kernel): void
    {
        $command = (new Application($kernel))->find('messenger:consume');
        (new CommandTester($command))->execute(['receivers' => ['async'], '--limit' => 1, '--time-limit' => 1]);
    }

    /**
     * @param list<class-string<\Throwable>> $expected
     */
    private function assertCapturedExceptions(array $expected): void
    {
        $actual = array_map(static function (Event $event): string {
            $exceptions = $event->getExceptions();

            return [] === $exceptions ? 'message:' . $event->getMessage() : $exceptions[0]->getType();
        }, StubTransport::$events);

        $this->assertSame($expected, $actual, $this->describeEvents());
    }

    private function getLogger(ContainerInterface $container, string $id): Logger
    {
        $logger = $container->get($id);
        $this->assertInstanceOf(Logger::class, $logger);

        return $logger;
    }

    private function getTestContainer(): ContainerInterface
    {
        $container = self::bootKernel()->getContainer()->get('test.service_container');
        $this->assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    private function describeEvents(): string
    {
        return implode("\n", array_map(static function (Event $event): string {
            return (string) json_encode(['msg' => $event->getMessage(), 'exc' => array_map(static function ($e) {
                return $e->getType() . ': ' . $e->getValue();
            }, $event->getExceptions()), 'extra' => $event->getExtra()]);
        }, StubTransport::$events));
    }
}
