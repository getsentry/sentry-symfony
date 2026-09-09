<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithHttpHeaders;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * @runTestsInSeparateProcesses
 */
final class HttpResponseCollectionEnd2EndTest extends TestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    public function testInferredContentTypeIsCollected(): void
    {
        [$response, $data] = $this->request('GET', '/prepared-response');

        $this->assertSame('{"name":"Alice","password":"secret"}', $response->getContent());
        $this->assertSame(['application/json'], $data['http.response.header.content-type']);
        $this->assertSame([$response->headers->get('Cache-Control')], $data['http.response.header.cache-control']);
        $this->assertArrayNotHasKey('http.response.body.data', $data);
    }

    public function testHeadResponseCollectsHeadersWithoutABody(): void
    {
        [$response, $data] = $this->request('HEAD', '/prepared-response');

        $this->assertSame('', $response->getContent());
        $this->assertSame(['application/json'], $data['http.response.header.content-type']);
        $this->assertSame([$response->headers->get('Cache-Control')], $data['http.response.header.cache-control']);
        $this->assertArrayNotHasKey('http.response.body.data', $data);
    }

    public function testSessionCookiesAndCacheHeadersAreCollected(): void
    {
        [$response, $data] = $this->request('GET', '/prepared-response?session=1', true);

        $this->assertSame('{"name":"Alice","password":"secret"}', $response->getContent());
        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('MOCKSESSID', $cookies[0]->getName());
        $this->assertSame('[Filtered]', $data['http.response.header.set_cookie.MOCKSESSID']);
        $this->assertSame([$response->headers->get('Cache-Control')], $data['http.response.header.cache-control']);
        $this->assertSame(['application/json'], $data['http.response.header.content-type']);
        $this->assertArrayNotHasKey('http.response.header.set-cookie', $data);
        $this->assertArrayNotHasKey('http.response.body.data', $data);
    }

    /**
     * @return array{Response, array<string, mixed>}
     */
    private function request(string $method, string $url, bool $session = false): array
    {
        $kernel = new KernelWithHttpHeaders(['data_collection' => []], $session);
        $client = new KernelBrowser($kernel);
        try {
            $client->request($method, $url);
            $response = $client->getResponse();
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
            $transactions = array_values(array_filter(StubTransport::$events, static function (Event $event): bool {
                return null !== $event->getTransaction();
            }));
            $this->assertCount(1, $transactions);
            $data = $transactions[0]->getContexts()['trace']['data'];
            $this->assertIsArray($data);

            return [$response, $data];
        } finally {
            $kernel->shutdown();
        }
    }
}
