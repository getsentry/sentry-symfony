<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DummyTestCommand extends Command
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
        $this->logger->error('dummy 1 error');

        return 0;
    }
}
