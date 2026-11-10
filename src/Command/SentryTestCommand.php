<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Command;

use Sentry\NoOpClient;
use Sentry\SentryBundle\Sentry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @final since version 4.12
 */
class SentryTestCommand extends Command
{
    /**
     * @var Sentry
     */
    private $sentry;

    public function __construct(Sentry $sentry)
    {
        parent::__construct();

        $this->sentry = $sentry;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->sentry->getClient();

        if ($client instanceof NoOpClient) {
            $output->writeln('<error>No client found</error>');
            $output->writeln('<info>Your DSN is probably missing, check your configuration</info>');

            return 1;
        }

        $dsn = $client->getOptions()->getDsn();

        if (null === $dsn) {
            $output->writeln('<error>No DSN configured in the current client, please check your configuration</error>');
            $output->writeln('<info>To debug further, try bin/console debug:config sentry</info>');

            return 1;
        }

        $output->writeln('<info>DSN correctly configured in the current client</info>');
        $output->writeln('Sending test message...');

        $eventId = $this->sentry->captureMessage('This is a test message from the Sentry bundle');

        if (null === $eventId) {
            $output->writeln('<error>Message not sent!</error>');
            $output->writeln('<warning>Check your DSN or your before_send callback if used</warning>');

            return 1;
        }

        $output->writeln("<info>Message sent successfully with ID $eventId</info>");

        return 0;
    }
}
