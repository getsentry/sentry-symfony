<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\KernelForBufferTest;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

if (!class_exists(KernelBrowser::class) && class_exists(Client::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
class BufferFlushEnd2EndTest extends WebTestCase
{
    protected function setUp(): void
    {
        StubTransport::$events = [];
    }

    protected static function getKernelClass(): string
    {
        return KernelForBufferTest::class;
    }

    /**
     * Test that log messages are properly buffered and flushed after kernel termination.
     */
    public function testLogMessagesBufferedAndFlushedAfterKernelTermination(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/buffer-flush');

        $this->assertResponseIsSuccessful();
        $this->assertEquals('OK', $client->getResponse()->getContent());

        // We only have 2 events because notice is not converted
        $this->assertCount(2, StubTransport::$events);

        $event = StubTransport::$events[0];
        $this->assertCount(2, $event->getBreadcrumbs());
        $this->assertEquals('Test warning message', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('Test error message', $event->getBreadcrumbs()[1]->getMessage());

        $event = StubTransport::$events[1];
        $this->assertCount(2, $event->getBreadcrumbs());
        $this->assertEquals('Test warning message', $event->getBreadcrumbs()[0]->getMessage());
        $this->assertEquals('Test error message', $event->getBreadcrumbs()[1]->getMessage());
    }
}
