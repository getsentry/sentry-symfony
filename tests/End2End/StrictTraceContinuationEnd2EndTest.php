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
final class StrictTraceContinuationEnd2EndTest extends WebTestCase
{
    private const INCOMING_TRACE_ID = '566e3688a61d4bc888951642d6f14a19';
    private const INCOMING_PARENT_SPAN_ID = '566e3688a61d4bc8';
    private const INCOMING_SENTRY_TRACE_HEADER = self::INCOMING_TRACE_ID . '-' . self::INCOMING_PARENT_SPAN_ID . '-1';

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

        file_put_contents(End2EndTest::SENT_EVENTS_LOG, '');
    }

    /**
     * @dataProvider strictTraceContinuationDataProvider
     */
    public function testStrictTraceContinuation(string $configFile, string $baggage, bool $expectedContinueTrace): void
    {
        $client = static::createClient([
            'extra_config_files' => [__DIR__ . '/App/config/strict_trace_continuation/' . $configFile],
        ]);

        $server = [
            'HTTP_SENTRY_TRACE' => self::INCOMING_SENTRY_TRACE_HEADER,
        ];

        if ('' !== $baggage) {
            $server['HTTP_BAGGAGE'] = $baggage;
        }

        $client->request('GET', '/tracing/ping-database', [], [], $server);

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Success', $response->getContent());

        $tracingEvent = $this->getTracingEvent();
        $contexts = $tracingEvent->getContexts();

        $this->assertArrayHasKey('trace', $contexts);

        $traceContext = $contexts['trace'];

        if ($expectedContinueTrace) {
            $this->assertSame(self::INCOMING_TRACE_ID, $traceContext['trace_id']);
            $this->assertSame(self::INCOMING_PARENT_SPAN_ID, $traceContext['parent_span_id']);
        } else {
            $this->assertNotSame(self::INCOMING_TRACE_ID, $traceContext['trace_id']);
            $this->assertArrayNotHasKey('parent_span_id', $traceContext);
        }
    }

    public function strictTraceContinuationDataProvider(): \Generator
    {
        yield ['strict_false_with_org.yml', 'sentry-org_id=1', true];
        yield ['strict_false_with_org.yml', '', true];
        yield ['strict_false_without_org.yml', 'sentry-org_id=1', true];
        yield ['strict_false_without_org.yml', '', true];
        yield ['strict_false_with_org.yml', 'sentry-org_id=2', false];
        yield ['strict_true_with_org.yml', 'sentry-org_id=1', true];
        yield ['strict_true_with_org.yml', '', false];
        yield ['strict_true_without_org.yml', 'sentry-org_id=1', false];
        yield ['strict_true_without_org.yml', '', true];
        yield ['strict_true_with_org.yml', 'sentry-org_id=2', false];
    }

    private function getTracingEvent(): Event
    {
        $tracingEvents = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
            return null !== $event->getTransaction();
        }));

        $this->assertCount(1, $tracingEvents);

        return $tracingEvents[0];
    }
}
