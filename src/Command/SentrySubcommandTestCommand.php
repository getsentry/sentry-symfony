<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class SentrySubcommandTestCommand extends Command
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var Command
     */
    private $subcommand;

    public function __construct(LoggerInterface $logger, Command $subcommand)
    {
        parent::__construct('sentry:subcommand:test');
        $this->logger = $logger;
        $this->subcommand = $subcommand;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->error('Subcommand will run now');

        if ($this->getApplication() !== null) {
            $this->getApplication()->doRun(new ArrayInput(['command' => $this->subcommand->getName()]), new NullOutput());
        }

        $this->logger->error('Breadcrumb after subcommand');

        return 0;
    }
}
