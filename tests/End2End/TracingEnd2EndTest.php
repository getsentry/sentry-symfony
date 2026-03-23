<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithTracing;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

if (!class_exists(KernelBrowser::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
class TracingEnd2EndTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return KernelWithTracing::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubTransport::$events = [];
    }

    public function testTracingWithDoctrineConnectionPing(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/ping-database');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('Success', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());

        $this->assertCount(1, StubTransport::$events, 'Wrong number of events captured');
        $this->assertTracingEventCount(1);
    }

    public function testTracingWithIgnoredTransaction(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/ignored-transaction');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals('Success', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());

        $this->assertCount(0, StubTransport::$events, 'Ignored transaction should not be sent');
        $this->assertTracingEventCount(0);
    }

    private function assertTracingEventCount(int $expectedCount): void
    {
        $tracingEvents = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
            return null !== $event->getTransaction();
        }));

        $this->assertCount($expectedCount, $tracingEvents, 'Wrong number of tracing events captured');
    }
}
