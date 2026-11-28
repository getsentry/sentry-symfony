<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithTracing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

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

    public function testTransactionCoversARequestRejectedAtTheFirewall(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/firewall-rejected');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $this->assertCount(1, StubTransport::$events, 'Wrong number of events captured');

        $transaction = StubTransport::$events[0];

        $this->assertSame('GET tracing_firewall_rejected', $transaction->getTransaction());
        $this->assertSame('unauthenticated', $transaction->getContexts()['trace']['status'] ?? null);
        $this->assertCount(1, $transaction->getSpans());
        $this->assertSame('firewall.token_lookup', $transaction->getSpans()[0]->getOp());
    }

    public function testSubRequestWithoutRouteDoesNotFinishTheTransaction(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/sub-request-without-route');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $transactions = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
            return 'GET tracing_sub_request_without_route' === $event->getTransaction();
        }));

        $this->assertCount(1, $transactions);

        $spanOps = array_map(static function ($span): ?string {
            return $span->getOp();
        }, $transactions[0]->getSpans());

        // The span was started after the sub-request, the transaction must still be open then
        $this->assertContains('after.sub_request', $spanOps);
    }

    private function assertTracingEventCount(int $expectedCount): void
    {
        $tracingEvents = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
            return null !== $event->getTransaction();
        }));

        $this->assertCount($expectedCount, $tracingEvents, 'Wrong number of tracing events captured');
    }
}
