<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SuccessCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('option1', null, InputOption::VALUE_NONE)
            ->addOption('option2', 'o2', InputOption::VALUE_OPTIONAL)
            ->addArgument('id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        return Command::SUCCESS;
    }
}
