<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\Fixtures;

use Sentry\Metrics\Types\Metric;

class SentryCallbacks
{
    public static function getBeforeSendMetric(): callable
    {
        return static function (Metric $metric): ?Metric {
            if ('test-counter' === $metric->getName()) {
                return null;
            }

            return $metric;
        };
    }
}
