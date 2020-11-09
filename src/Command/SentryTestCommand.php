<?php

namespace Sentry\SentryBundle\Command;

use Sentry\SentrySdk;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SentryTestCommand extends Command
{
    protected static $defaultName = 'sentry:test';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $currentHub = SentrySdk::getCurrentHub();
        $client = $currentHub->getClient();

        if (! $client) {
            $output->writeln('<error>No client found</error>');
            $output->writeln('<info>Your DSN is probably missing, check your configuration</info>');

            return 1;
        }

        $dsn = $client->getOptions()->getDsn();

        if ($dsn) {
            $output->writeln('<info>DSN correctly configured in the current client</info>');
        } else {
            $output->writeln('<error>No DSN configured in the current client, please check your configuration</error>');
            $output->writeln('<info>To debug further, try bin/console debug:config sentry</info>');

            return 1;
        }

        $output->writeln('Sending test message...');

        $eventId = $currentHub->captureMessage('This is a test message from the Sentry bundle');

        if ($eventId) {
            $output->writeln("<info>Message sent successfully with ID $eventId</info>");
        } else {
            $output->writeln('<error>Message not sent!</error>');
            $output->writeln('<warning>Check your DSN or your before_send callback if used</warning>');

            return 1;
        }

        return 0;
    }
}
