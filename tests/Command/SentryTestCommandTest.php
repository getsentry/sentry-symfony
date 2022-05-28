<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Command;

use Prophecy\Argument;
use Sentry\ClientInterface;
use Sentry\EventId;
use Sentry\Options;
use Sentry\SentryBundle\Command\SentryTestCommand;
use Sentry\SentryBundle\Tests\BaseTestCase;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SentryTestCommandTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        // reset current Hub to avoid leaking the mock outside of this tests
        SentrySdk::init();
    }

    public function testExecuteSuccessfully(): void
    {
        $options = new Options(['dsn' => 'http://public:secret@example.com/sentry/1']);
        $client = $this->prophesize(ClientInterface::class);
        $client->getOptions()
            ->willReturn($options);

        $hub = $this->prophesize(HubInterface::class);
        $hub->getClient()
            ->willReturn($client->reveal());
        $lastEventId = EventId::generate();
        $hub->captureMessage(Argument::containingString('test'), Argument::cetera())
            ->shouldBeCalled()
            ->willReturn($lastEventId);

        SentrySdk::setCurrentHub($hub->reveal());

        $commandTester = $this->executeCommand();

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DSN correctly configured', $output);
        $this->assertStringContainsString('Sending test message', $output);
        $this->assertStringContainsString('Message sent', $output);
        $this->assertStringContainsString((string) $lastEventId, $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testExecuteFailsDueToMissingDSN(): void
    {
        $client = $this->prophesize(ClientInterface::class);
        $client->getOptions()
            ->willReturn(new Options());

        $hub = $this->prophesize(HubInterface::class);
        $hub->getClient()
            ->willReturn($client->reveal());

        SentrySdk::setCurrentHub($hub->reveal());

        $commandTester = $this->executeCommand();

        $this->assertNotSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('No DSN configured', $output);
        $this->assertStringContainsString('try bin/console debug:config sentry', $output);
    }

    public function testExecuteFailsDueToMessageNotSent(): void
    {
        $options = new Options(['dsn' => 'http://public:secret@example.com/sentry/1']);
        $client = $this->prophesize(ClientInterface::class);
        $client->getOptions()
            ->willReturn($options);

        $hub = $this->prophesize(HubInterface::class);
        $hub->getClient()
            ->willReturn($client->reveal());
        $hub->captureMessage(Argument::containingString('test'), Argument::cetera())
            ->shouldBeCalled()
            ->willReturn(null);

        SentrySdk::setCurrentHub($hub->reveal());

        $commandTester = $this->executeCommand();

        $this->assertNotSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('DSN correctly configured', $output);
        $this->assertStringContainsString('Sending test message', $output);
        $this->assertStringContainsString('Message not sent', $output);
    }

    public function testExecuteFailsDueToMissingClient(): void
    {
        $hub = $this->prophesize(HubInterface::class);
        $hub->getClient()
            ->willReturn(null);

        SentrySdk::setCurrentHub($hub->reveal());

        $commandTester = $this->executeCommand();

        $this->assertNotSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('No client found', $output);
        $this->assertStringContainsString('DSN is probably missing', $output);
    }

    private function executeCommand(): CommandTester
    {
        $command = new SentryTestCommand();
        $command->setName('sentry:test');

        $application = new Application();
        $application->add($command);

        $command = $application->find('sentry:test');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
        ]);

        return $commandTester;
    }
}
