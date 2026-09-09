<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithHttpHeaders;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * @runTestsInSeparateProcesses
 */
final class HttpResponseCollectionEnd2EndTest extends TestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    /**
     * @dataProvider responseProvider
     */
    public function testPreparedResponseIsCollected(string $method, bool $session): void
    {
        $kernel = new KernelWithHttpHeaders(['data_collection' => []], $session);
        $client = new KernelBrowser($kernel);

        try {
            $client->request($method, '/prepared-response' . ($session ? '?session=1' : ''));
            $response = $client->getResponse();
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
            $transactions = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return null !== $event->getTransaction();
            }));
            $this->assertCount(1, $transactions);
            $data = $transactions[0]->getContexts()['trace']['data'];
            $this->assertIsArray($data);
            $this->assertSame(['application/json'], $data['http.response.header.content-type']);
            $this->assertSame([$response->headers->get('Cache-Control')], $data['http.response.header.cache-control']);

            if ('HEAD' === $method) {
                $this->assertSame('', $response->getContent());
                $this->assertArrayNotHasKey('http.response.body.data', $data);
            } else {
                $this->assertSame('{"name":"Alice","password":"secret"}', $response->getContent());
                $this->assertArrayNotHasKey('http.response.body.data', $data);
            }

            if ($session) {
                $cookies = $response->headers->getCookies();
                $this->assertCount(1, $cookies);
                $this->assertSame('MOCKSESSID', $cookies[0]->getName());
                $this->assertSame('[Filtered]', $data['http.response.header.set_cookie.MOCKSESSID']);
                $this->assertArrayNotHasKey('http.response.header.set-cookie', $data);
            }
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @return \Generator<mixed>
     */
    public function responseProvider(): \Generator
    {
        yield 'inferred JSON content type' => ['GET', false];
        yield 'HEAD body removed' => ['HEAD', false];
        yield 'session cookie and cache headers' => ['GET', true];
    }
}
