<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithTraceIgnoreStatusCodes;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

if (!class_exists(KernelBrowser::class) && class_exists(Client::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
class TraceIgnoreStatusCodesEnd2EndTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return KernelWithTraceIgnoreStatusCodes::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubTransport::$events = [];
    }

    public function testTransactionWithIgnoredStatusCodeIsNotSent(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/not-found');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(0, StubTransport::$events, 'Transaction with an ignored status code should not be sent');
    }

    public function testTransactionWithIgnoredStatusCodeRangeIsNotSent(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/status-code-in-ignored-range');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(599, $response->getStatusCode());
        $this->assertCount(0, StubTransport::$events, 'Transaction with a status code in an ignored range should not be sent');
    }

    public function testTransactionWithNonIgnoredStatusCodeIsSent(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/ping-database');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(1, StubTransport::$events, 'Wrong number of events captured');

        $tracingEvents = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
            return null !== $event->getTransaction();
        }));

        $this->assertCount(1, $tracingEvents, 'Wrong number of tracing events captured');
    }
}
