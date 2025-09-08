<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Doctrine\DBAL\Connection;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithTracing;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @runTestsInSeparateProcesses
 */
class TracingCacheEnd2EndTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return KernelWithTracing::class;
    }

    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    public function testPopulateStringCache(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/cache/populate-string');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];
        $this->assertCount(2, $event->getSpans());

        $getSpan = $event->getSpans()[0];
        $this->assertEquals('cache.get', $getSpan->getOp());
        $this->assertEquals(14, $getSpan->getData('cache.item_size'));
        $this->assertFalse($getSpan->getData('cache.hit'));

        $putSpan = $event->getSpans()[1];
        $this->assertEquals('cache.put', $putSpan->getOp());
        $this->assertEquals(14, $putSpan->getData('cache.item_size'));
        $this->assertNull($putSpan->getData('cache.hit'));

        // assert that put is a sibling span to get
        $this->assertNotEquals($getSpan->getSpanId(), $putSpan->getParentSpanId());
        $this->assertEquals($getSpan->getParentSpanId(), $putSpan->getParentSpanId());
    }

    public function testCacheHit(): void
    {
        $client = static::createClient(['debug' => false]);

        // Populate the cache by having a cache miss
        $client->request('GET', '/tracing/cache/populate-string');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // Reset transport so we only get events for cache HITs
        StubTransport::$events = [];

        $client->request('GET', '/tracing/cache/populate-string');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);

        $event = StubTransport::$events[0];
        $this->assertCount(1, $event->getSpans());

        $span = $event->getSpans()[0];
        $this->assertEquals('cache.get', $span->getOp());
        $this->assertEquals(14, $span->getData('cache.item_size'));
        $this->assertTrue($span->getData('cache.hit'));
    }

    public function testNonStringItemSize(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/cache/populate-integer');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $this->assertCount(1, StubTransport::$events);

        $event = StubTransport::$events[0];
        $this->assertCount(2, $event->getSpans());

        $span = $event->getSpans()[0];
        $this->assertEquals('cache.get', $span->getOp());
        $this->assertNull($span->getData('cache.item_size'));
    }

    public function testDeleteCacheSpan(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/cache/delete');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];

        $this->assertCount(1, $event->getSpans());

        $span = $event->getSpans()[0];
        $this->assertEquals('cache.remove', $span->getOp());
        $this->assertNull($span->getData('cache.item_size'));
    }

    public function testGetWithDbSpan(): void
    {
        if (!class_exists(Connection::class)) {
            $this->markTestSkipped('Skipped if doctrine is not available');
        }
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/cache/populate-string-with-db');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];

        $this->assertCount(3, $event->getSpans());
        $getSpan = $event->getSpans()[0];
        $this->assertEquals('cache.get', $getSpan->getOp());

        $dbSpan = $event->getSpans()[1];
        $this->assertEquals('db.sql.query', $dbSpan->getOp());

        $putSpan = $event->getSpans()[2];
        $this->assertEquals('cache.put', $putSpan->getOp());

        // assert that the DB call is a child span of the get operation
        $this->assertEquals($getSpan->getSpanId(), $dbSpan->getParentSpanId());

        // assert that get and put are siblings
        $this->assertEquals($getSpan->getParentSpanId(), $putSpan->getParentSpanId());
    }

    public function testPsrCachePopulateString(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/cache/psr/populate-string');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];

        // PSR caches will only have two spans, one for the get and one for put
        $this->assertCount(2, $event->getSpans());

        $getSpan = $event->getSpans()[0];
        $this->assertEquals('cache.get', $getSpan->getOp());

        $putSpan = $event->getSpans()[1];
        $this->assertEquals('cache.put', $putSpan->getOp());
    }

    public function testPsrCacheHit(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/cache/psr/populate-string');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        // This populates the cache and is tested in a different test
        $this->assertCount(1, StubTransport::$events);
        $this->assertCount(2, StubTransport::$events[0]->getSpans());
        StubTransport::$events = [];

        $client->request('PUT', '/tracing/cache/psr/populate-string');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];

        $this->assertCount(1, $event->getSpans());
        $span = $event->getSpans()[0];
        $this->assertEquals('cache.get', $span->getOp());
        $this->assertTrue($span->getData('cache.hit'));
    }

    public function testPsrCacheDelete(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/cache/psr/delete');
        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $this->assertCount(1, StubTransport::$events);
        $event = StubTransport::$events[0];

        $this->assertCount(1, $event->getSpans());
        $span = $event->getSpans()[0];
        $this->assertEquals('cache.remove', $span->getOp());
        $this->assertNull($span->getData('cache.item_size'));
    }
}
