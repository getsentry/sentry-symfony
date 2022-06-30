<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\Kernel;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

if (!class_exists(KernelBrowser::class) && class_exists(Client::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
class End2EndTest extends WebTestCase
{
    public const SENT_EVENTS_LOG = '/tmp/sentry_e2e_test_sent_events.log';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        file_put_contents(self::SENT_EVENTS_LOG, '');
    }

    public function testGet200(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/200');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testGet200BehindFirewall(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/secured/200');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testGet200WithSubrequest(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/subrequest');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testGet404(): void
    {
        $client = static::createClient(['debug' => false]);

        try {
            $client->request('GET', '/missing-page');

            $response = $client->getResponse();

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(404, $response->getStatusCode());
        } catch (\Throwable $exception) {
            if (!$exception instanceof NotFoundHttpException) {
                throw $exception;
            }

            $this->assertSame('No route found for "GET /missing-page"', $exception->getMessage());
        }

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testGet500(): void
    {
        $client = static::createClient();

        try {
            $client->request('GET', '/exception');

            $response = $client->getResponse();

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(500, $response->getStatusCode());
            $this->assertStringContainsString('intentional error', $response->getContent() ?: '');
        } catch (\Throwable $exception) {
            if (!$exception instanceof \RuntimeException) {
                throw $exception;
            }

            $this->assertSame('This is an intentional error', $exception->getMessage());
        }

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    /**
     * @requires PHP >= 7.3
     */
    public function testGetFatal(): void
    {
        $client = static::createClient();

        try {
            $client->insulate(true);
            $client->request('GET', '/fatal');

            $response = $client->getResponse();

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(500, $response->getStatusCode());
            $this->assertStringNotContainsString('not happen', $response->getContent() ?: '');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsStringIgnoringCase('error', $exception->getMessage());
            $this->assertStringContainsStringIgnoringCase('contains 1 abstract method', $exception->getMessage());
            $this->assertStringContainsStringIgnoringCase('MainController.php', $exception->getMessage());
            $this->assertStringContainsStringIgnoringCase('eval()\'d code on line', $exception->getMessage());
        }

        $this->assertEventCount(1);
    }

    public function testNotice(): void
    {
        $client = static::createClient();

        /** @var HubInterface $hub */
        $hub = $client->getContainer()->get('test.hub');
        $sentryClient = $hub->getClient();

        $this->assertNotNull($sentryClient);

        $sentryClient->getOptions()->setCaptureSilencedErrors(true);

        $client->request('GET', '/notice');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertLastEventIdIsNotNull($client);
        $this->assertEventCount(1);
    }

    public function testCommand(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        try {
            $application->doRun(new ArgvInput(['bin/console', 'main-command', '--option1', '--option2=foo', 'bar']), new NullOutput());
        } catch (\RuntimeException $e) {
            $this->assertSame('This is an intentional error', $e->getMessage());
        }

        $this->assertEventCount(1);
        $this->assertCount(1, StubTransportFactory::$events);
        $this->assertSame(
            ['Full command' => 'main-command --option1 --option2=foo bar'],
            StubTransportFactory::$events[0]->getExtra()
        );
    }

    public function testMessengerCaptureHardFailure(): void
    {
        $this->skipIfMessengerIsMissing();

        $client = static::createClient();

        $client->request('GET', '/dispatch-unrecoverable-message');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->consumeOneMessage($client->getKernel());

        $this->assertLastEventIdIsNotNull($client);
    }

    public function testMessengerCaptureSoftFailCanBeDisabled(): void
    {
        $this->skipIfMessengerIsMissing();

        $client = static::createClient();

        $client->request('GET', '/dispatch-message');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->consumeOneMessage($client->getKernel());

        $this->assertLastEventIdIsNull($client);
    }

    public function testMessengerCleanScope(): void
    {
        $this->skipIfMessengerIsMissing();

        $client = static::createClient();

        $client->request('GET', '/dispatch-message?foo=bar');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->consumeOneMessage($client->getKernel());

//        $hub = $this->getHub($client);

//        $hub->configureScope(function(Scope $scope) {
//
//            $event = $scope->applyToEvent(Event::createEvent());

// no context found, maybe because the fix is working well and has cleaned correctly the hub?
//            print_r($event->getContexts());
//        });

        $this->assertLastEventIdIsNull($client);
    }

    private function consumeOneMessage(KernelInterface $kernel): void
    {
        $application = new Application($kernel);

        $command = $application->find('messenger:consume');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'receivers' => ['async'],
            '--limit' => 1,
            '--time-limit' => 1,
            '-vvv' => true,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    private function assertLastEventIdIsNotNull(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $this->assertNotNull($container);

        $hub = $container->get('test.hub');
        $this->assertInstanceOf(HubInterface::class, $hub);

        $this->assertNotNull($hub->getLastEventId(), 'Last error not captured');
    }

    private function assertEventCount(int $expectedCount): void
    {
        $events = file_get_contents(self::SENT_EVENTS_LOG);
        $this->assertNotFalse($events, 'Cannot read sent events log');
        $listOfEvents = array_filter(explode(StubTransportFactory::SEPARATOR, trim($events)));
        $this->assertCount($expectedCount, $listOfEvents, 'Wrong number of events sent: ' . \PHP_EOL . $events);
    }

    private function assertLastEventIdIsNull(KernelBrowser $client): void
    {
        $hub = $this->getHub($client);

        $this->assertNull($hub->getLastEventId(), 'Some error was captured');
    }

    private function skipIfMessengerIsMissing(): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            $this->markTestSkipped('Messenger missing');
        }
    }

    protected function getHub(KernelBrowser $client)
    {
        $container = $client->getContainer();
        $this->assertNotNull($container);

        $hub = $container->get('test.hub');
        $this->assertInstanceOf(HubInterface::class, $hub);

        return $hub;
    }
}
