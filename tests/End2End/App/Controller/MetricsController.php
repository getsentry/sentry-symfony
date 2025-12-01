<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Symfony\Component\HttpFoundation\Response;

use function Sentry\trace_metrics;

class MetricsController
{
    public function metrics(): Response
    {
        trace_metrics()->count('test-counter', 10);
        trace_metrics()->gauge('test-gauge', 20.51);
        trace_metrics()->distribution('test-distribution', 100.81);

        return new Response();
    }
}
