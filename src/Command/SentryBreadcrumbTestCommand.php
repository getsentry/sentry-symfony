<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SentryBreadcrumbTestCommand extends Command
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct('sentry:breadcrumb:test');
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->error('Breadcrumb error');

        throw new \RuntimeException('Breadcrumb error');
    }
}
