<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;

if (!class_exists(KernelBrowser::class) && class_exists(Client::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
class TraceIgnoreStatusCodesEnd2EndTest extends WebTestCase
{
    /**
     * @param array{extra_config_files?: list<string>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new KernelWithExtraConfig(array_merge([
            __DIR__ . '/App/tracing.yml',
        ], $options['extra_config_files'] ?? []));
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubTransport::$events = [];
    }

    public function testTransactionWithNotFoundStatusCodeIsNotSentByDefault(): void
    {
        $client = static::createClient();

        $client->request('GET', '/tracing/not-found');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(0, StubTransport::$events, 'Transaction with a 404 status code should not be sent by default');
    }

    public function testTransactionWithNotFoundStatusCodeIsSentWhenNoStatusCodesAreIgnored(): void
    {
        $client = static::createClient([
            'extra_config_files' => [__DIR__ . '/App/trace_ignore_status_codes_empty.yml'],
        ]);

        $client->request('GET', '/tracing/not-found');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(1, StubTransport::$events, 'Wrong number of events captured');
        $this->assertNotNull(StubTransport::$events[0]->getTransaction(), 'The captured event is not a transaction');
    }

    public function testTransactionWithIgnoredStatusCodeIsNotSent(): void
    {
        $client = static::createClient([
            'extra_config_files' => [__DIR__ . '/App/trace_ignore_status_codes.yml'],
        ]);

        $client->request('GET', '/tracing/not-found');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertCount(0, StubTransport::$events, 'Transaction with an ignored status code should not be sent');
    }

    public function testTransactionWithIgnoredStatusCodeRangeIsNotSent(): void
    {
        $client = static::createClient([
            'extra_config_files' => [__DIR__ . '/App/trace_ignore_status_codes.yml'],
        ]);

        $client->request('GET', '/tracing/status-code-in-ignored-range');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(599, $response->getStatusCode());
        $this->assertCount(0, StubTransport::$events, 'Transaction with a status code in an ignored range should not be sent');
    }

    public function testTransactionWithNonIgnoredStatusCodeIsSent(): void
    {
        $client = static::createClient([
            'extra_config_files' => [__DIR__ . '/App/trace_ignore_status_codes.yml'],
        ]);

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
