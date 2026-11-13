<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Sentry\traceMetrics;

class MetricsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        traceMetrics()->count('test-counter', 10);
        traceMetrics()->gauge('test-gauge', 20.51);
        traceMetrics()->distribution('test-distribution', 100.81);

        return 0;
    }
}
