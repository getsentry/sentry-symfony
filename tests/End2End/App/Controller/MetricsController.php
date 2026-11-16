<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Symfony\Component\HttpFoundation\Response;

use function Sentry\metrics;

class MetricsController
{
    public function metrics(): Response
    {
        metrics()->count('test-counter', 10);
        metrics()->gauge('test-gauge', 20.51);
        metrics()->distribution('test-distribution', 100.81);

        return new Response();
    }
}
