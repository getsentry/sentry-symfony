<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class LoggingCommand extends Command
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger->debug('Debug Log');

        $this->logger->info('Info Log');

        $this->logger->warning('Warn Log');

        $this->logger->error('Error Log');

        return 0;
    }
}
