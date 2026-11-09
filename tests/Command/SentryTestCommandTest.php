<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\EventId;
use Sentry\NoOpClient;
use Sentry\Options;
use Sentry\SentryBundle\Command\SentryTestCommand;
use Sentry\SentryBundle\SentryManager;
use Symfony\Component\Console\Tester\CommandTester;

final class SentryTestCommandTest extends TestCase
{
    /**
     * @var SentryManager&MockObject
     */
    private $sentry;

    /**
     * @var ClientInterface&MockObject
     */
    private $client;

    /**
     * @var CommandTester
     */
    private $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sentry = $this->createMock(SentryManager::class);
        $this->client = $this->createMock(ClientInterface::class);
        $this->command = new CommandTester(new SentryTestCommand($this->sentry));
    }

    public function testExecute(): void
    {
        $lastEventId = EventId::generate();

        $this->client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['dsn' => 'https://public:secret@example.com/sentry/1']));

        $this->sentry->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $this->sentry->expects($this->once())
            ->method('captureMessage')
            ->with('This is a test message from the Sentry bundle')
            ->willReturn($lastEventId);

        $exitCode = $this->command->execute([]);
        $output = $this->command->getDisplay();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('DSN correctly configured in the current client', $output);
        $this->assertStringContainsString('Sending test message...', $output);
        $this->assertStringContainsString("Message sent successfully with ID $lastEventId", $output);
    }

    public function testExecuteFailsDueToMissingDSN(): void
    {
        $this->client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options());

        $this->sentry->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $exitCode = $this->command->execute([]);
        $output = $this->command->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No DSN configured in the current client, please check your configuration', $output);
        $this->assertStringContainsString('To debug further, try bin/console debug:config sentry', $output);
    }

    public function testExecuteFailsDueToMessageNotSent(): void
    {
        $this->client->expects($this->once())
            ->method('getOptions')
            ->willReturn(new Options(['dsn' => 'https://public:secret@example.com/sentry/1']));

        $this->sentry->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $this->sentry->expects($this->once())
            ->method('captureMessage')
            ->with('This is a test message from the Sentry bundle')
            ->willReturn(null);

        $exitCode = $this->command->execute([]);
        $output = $this->command->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('DSN correctly configured in the current client', $output);
        $this->assertStringContainsString('Sending test message...', $output);
        $this->assertStringContainsString('Message not sent!', $output);
        $this->assertStringContainsString('Check your DSN or your before_send callback if used', $output);
    }

    public function testExecuteFailsDueToMissingClient(): void
    {
        $this->sentry->expects($this->once())
            ->method('getClient')
            ->willReturn(new NoOpClient());

        $exitCode = $this->command->execute([]);
        $output = $this->command->getDisplay();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No client found', $output);
        $this->assertStringContainsString('Your DSN is probably missing, check your configuration', $output);
    }
}
