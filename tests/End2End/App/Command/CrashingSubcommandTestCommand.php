<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class CrashingSubcommandTestCommand extends Command
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct();
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->error('subcommand crash 1 error');

        if (null !== $this->getApplication()) {
            $this->getApplication()->doRun(new ArrayInput(['command' => 'sentry:breadcrumb:test']), new NullOutput());
        }

        $this->logger->error('subcommand error 2 error');

        return 0;
    }
}
