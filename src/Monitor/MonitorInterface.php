<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monitor;

use Sentry\CheckIn;

interface MonitorInterface
{
    public function inProgress(CheckIn $previous = null): CheckIn;

    public function error(CheckIn $previous = null): CheckIn;

    public function ok(CheckIn $previous = null): CheckIn;
}
