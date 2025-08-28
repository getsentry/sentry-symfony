<?php

namespace Sentry\SentryBundle\Tests\End2End\App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CrashCommand extends Command
{

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $subcommand;

    public function __construct(LoggerInterface $logger, string $subcommand = null)
    {
        parent::__construct();
        $this->logger = $logger;
        $this->subcommand = $subcommand;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger->warning("Executing subcommand if exists");

        if ($this->subcommand !== null) {
            $this->getApplication()->doRun(new ArgvInput(['bin/console', 'log:test']), $output);
        }

        $this->logger->emergency("About to crash");

        throw new \RuntimeException("Crash in command");
    }

}
