<?php

namespace Sentry\SentryBundle\Tests\End2End\App\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Sentry\trace_metrics;

class MetricsCommand extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        trace_metrics()->count('test-counter', 10);
        trace_metrics()->gauge('test-gauge', 20.51);
        trace_metrics()->distribution('test-distribution', 100.81);

        return 0;
    }

}
