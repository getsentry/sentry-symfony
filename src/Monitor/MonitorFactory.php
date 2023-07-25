<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monitor;

use Sentry\MonitorConfig;
use Sentry\SentrySdk;

class MonitorFactory implements MonitorFactoryInterface
{
    /**
     * @var string
     */
    private $environment;
    /**
     * @var string|null
     */
    private $release;

    /**
     * @param string $environment the configured environment
     */
    public function __construct(string $environment, string $release = null)
    {
        $this->environment = $environment;
        $this->release = $release;
    }

    public function getMonitor(string $slug, MonitorConfig $monitorConfig): MonitorInterface
    {
        $hub = SentrySdk::getCurrentHub();

        return new Monitor($hub, $monitorConfig, $slug, $this->environment, $this->release);
    }
}
