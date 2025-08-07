<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\Kernel;
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
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    /**
     * Test that log messages from a controller action are properly flushed after kernel termination.
     */
    public function testLogMessagesAvailableAfterKernelTermination(): void
    {
        StubTransport::$events = [];

        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/buffer-flush');

        $this->assertResponseIsSuccessful();
        $this->assertEquals('OK', $client->getResponse()->getContent());

        $events = StubTransport::$events;

        $this->assertCount(2, $events);

        $foundMessages = [];

        foreach ($events as $event) {
            if ($event->getMessage()) {
                $foundMessages[] = $event->getMessage();
            }
        }

        $this->assertContains('Test warning message', $foundMessages);
        $this->assertContains('Test error message', $foundMessages);
    }
}
