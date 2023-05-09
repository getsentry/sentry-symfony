<?php

namespace Sentry\SentryBundle\Cron;

use Sentry\CheckIn;

interface CronJobInterface
{
    public function inProgress(?CheckIn $previous = null): CheckIn;
    public function error(?CheckIn $previous = null): CheckIn;
    public function ok(?CheckIn $previous = null): CheckIn;
}
