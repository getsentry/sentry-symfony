<?php

namespace Sentry\SentryBundle\Test\Command;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Command\SentryTestCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class SentryTestCommandTest extends TestCase
{
    public function testExecuteFailsDueToMissingDsn(): void
    {
        $application = new Application();
        $application->add(new SentryTestCommand());

        $command = $application->find('sentry:test');
        $commandTester = new CommandTester($command);
        $commandTester->execute([
            'command'  => $command->getName(),
        ]);

        $this->assertNotSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        $this->assertContains('No client found', $output);
        $this->assertContains('DSN is probably missing', $output);
    }
}
