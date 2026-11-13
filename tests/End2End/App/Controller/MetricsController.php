<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Symfony\Component\HttpFoundation\Response;

use function Sentry\traceMetrics;

class MetricsController
{
    public function metrics(): Response
    {
        traceMetrics()->count('test-counter', 10);
        traceMetrics()->gauge('test-gauge', 20.51);
        traceMetrics()->distribution('test-distribution', 100.81);

        return new Response();
    }
}
