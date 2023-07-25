<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monitor;

use Sentry\MonitorConfig;

interface MonitorFactoryInterface
{
    /**
     * @param MonitorConfig $monitorConfig the monitor configuration
     *
     * @return MonitorInterface the monitor
     */
    public function getMonitor(string $slug, MonitorConfig $monitorConfig): MonitorInterface;
}
