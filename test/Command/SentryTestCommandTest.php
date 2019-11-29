<?php

namespace Sentry\SentryBundle\Test\Command;

use Prophecy\Argument;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\Command\SentryTestCommand;
use Sentry\SentryBundle\Test\BaseTestCase;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SentryTestCommandTest extends BaseTestCase
{
    public function testExecuteSuccessfully(): void
    {
        $options = new Options(['dsn' => 'http://public:secret@example.com/sentry/1']);
        $client = $this->prophesize(ClientInterface::class);
        $client->getOptions()
            ->willReturn($options);

        $hub = $this->prophesize(HubInterface::class);
        $hub->getClient()
            ->willReturn($client->reveal());
        $lastEventId = 'abcdef0123456';
        $hub->captureMessage(Argument::containingString('test'), Argument::cetera())
            ->shouldBeCalled()
            ->willReturn($lastEventId);

        $this->setCurrentHub($hub->reveal());

        $commandTester = $this->executeCommand();

        $output = $commandTester->getDisplay();
        $this->assertContains('DSN correctly configured', $output);
        $this->assertContains('Sending test message', $output);
        $this->assertContains('Message sent', $output);
        $this->assertContains($lastEventId, $output);
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

        $this->setCurrentHub($hub->reveal());

        $commandTester = $this->executeCommand();

        $this->assertNotSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertContains('No DSN configured', $output);
        $this->assertContains('try bin/console debug:config sentry', $output);
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

        $this->setCurrentHub($hub->reveal());

        $commandTester = $this->executeCommand();

        $this->assertNotSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertContains('DSN correctly configured', $output);
        $this->assertContains('Sending test message', $output);
        $this->assertContains('Message not sent', $output);
    }

    public function testExecuteFailsDueToMissingClient(): void
    {
        $hub = $this->prophesize(HubInterface::class);
        $hub->getClient()
            ->willReturn(null);

        $this->setCurrentHub($hub->reveal());

        $commandTester = $this->executeCommand();

        $this->assertNotSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertContains('No client found', $output);
        $this->assertContains('DSN is probably missing', $output);
    }

    private function executeCommand(): CommandTester
    {
        $application = new Application();
        $application->add(new SentryTestCommand());

        $command = $application->find('sentry:test');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command' => $command->getName(),
        ]);

        return $commandTester;
    }
}
