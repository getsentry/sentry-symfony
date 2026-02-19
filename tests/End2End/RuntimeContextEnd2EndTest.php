<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @runTestsInSeparateProcesses
 */
final class RuntimeContextEnd2EndTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    public function testRuntimeContextDoesNotLeakAcrossRequests(): void
    {
        $client = static::createClient(['debug' => false]);

        if (method_exists($client, 'disableReboot')) {
            $client->disableReboot();
        }

        $client->request('GET', '/runtime-context?request=first&leak=first-only');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $firstPayload = $this->decodeResponsePayload((string) $client->getResponse()->getContent());

        $client->request('GET', '/runtime-context?request=second');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $secondPayload = $this->decodeResponsePayload((string) $client->getResponse()->getContent());

        $client->request('GET', '/runtime-context?request=third&leak=third-only');
        $this->assertSame(200, $client->getResponse()->getStatusCode());
        $thirdPayload = $this->decodeResponsePayload((string) $client->getResponse()->getContent());

        $this->assertCount(3, StubTransport::$events);

        $firstTags = StubTransport::$events[0]->getTags();
        $secondTags = StubTransport::$events[1]->getTags();
        $thirdTags = StubTransport::$events[2]->getTags();

        $this->assertSame('first', $firstTags['runtime.request'] ?? null);
        $this->assertSame('first-only', $firstTags['runtime.leak'] ?? null);

        $this->assertSame('second', $secondTags['runtime.request'] ?? null);
        $this->assertArrayNotHasKey('runtime.leak', $secondTags);

        $this->assertSame('third', $thirdTags['runtime.request'] ?? null);
        $this->assertSame('third-only', $thirdTags['runtime.leak'] ?? null);

        $this->assertNotSame($firstPayload['runtime_context_id'], $secondPayload['runtime_context_id']);
        $this->assertNotSame($secondPayload['runtime_context_id'], $thirdPayload['runtime_context_id']);
    }

    /**
     * @return array{runtime_context_id: string}
     */
    private function decodeResponsePayload(string $responseContent): array
    {
        $payload = json_decode($responseContent, true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('runtime_context_id', $payload);
        $this->assertIsString($payload['runtime_context_id']);

        return $payload;
    }
}
