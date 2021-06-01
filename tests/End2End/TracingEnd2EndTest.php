<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\KernelWithTracing;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

if (!class_exists(KernelBrowser::class)) {
    /** @phpstan-ignore-next-line */
    class_alias(Client::class, KernelBrowser::class);
}

class TracingEnd2EndTest extends WebTestCase
{
    public const SENT_EVENTS_LOG = '/tmp/sentry_e2e_test_sent_events.log';

    protected static function getKernelClass(): string
    {
        return KernelWithTracing::class;
    }

    protected function setUp(): void
    {
        parent::setUp();

        file_put_contents(self::SENT_EVENTS_LOG, '');
    }

    public function testTracingWithDoctrineConnectionPing(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/tracing/ping-database');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $this->assertLastEventIdIsNotNull($client);
        $this->assertTracingEventCount(1);
    }

    private function assertLastEventIdIsNotNull(KernelBrowser $client): void
    {
        $container = $client->getContainer();
        $this->assertNotNull($container);

        $hub = $container->get('test.hub');
        $this->assertInstanceOf(HubInterface::class, $hub);

        $this->assertNotNull($hub->getLastEventId(), 'Last error not captured');
    }

    private function assertTracingEventCount(int $expectedCount): void
    {
        $events = file_get_contents(self::SENT_EVENTS_LOG);
        $this->assertNotFalse($events, 'Cannot read sent events log');
        $listOfTracingEvents = array_filter(explode(StubTransportFactory::SEPARATOR, trim($events)), static function (string $elem) {
            return str_contains('TRACING', $elem);
        });

        $this->assertCount($expectedCount, $listOfTracingEvents, 'Wrong number of tracing events sent: ' . \PHP_EOL . $events);
    }
}
