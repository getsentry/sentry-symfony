<?php

namespace Sentry\SentryBundle\Tests\End2End\Fixtures;

use Sentry\Metrics\Types\Metric;

class SentryCallbacks
{

    public static function getBeforeSendMetric(): callable
    {
        return static function (Metric $metric): ?Metric {
            if ($metric->getName() === 'test-counter') {
                return null;
            }
            return $metric;
        };
    }

}
