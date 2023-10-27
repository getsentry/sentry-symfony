<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\CronMonitoring;

use Sentry\CheckInStatus;
use Sentry\MonitorConfig;
use Sentry\State\HubInterface;

class CronMonitor
{
    /**
     * @var MonitorConfig
     */
    private $monitorConfig;

    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var string
     */
    private $slug;

    /**
     * @var string
     */
    private $checkInId;

    public function __construct(HubInterface $hub, MonitorConfig $monitorConfig, string $slug)
    {
        $this->hub = $hub;
        $this->monitorConfig = $monitorConfig;
        $this->slug = $slug;
    }

    public function start(): void
    {
        $this->checkInId = $this->hub->captureCheckIn(
            $this->slug,
            CheckInStatus::inProgress(),
            null,
            $this->monitorConfig
        );
    }

    public function finishSuccess(): ?string
    {
        return $this->hub->captureCheckIn(
            $this->slug,
            CheckInStatus::OK(),
            null,
            $this->monitorConfig,
            $this->checkInId
        );
    }

    public function finishError(): ?string
    {
        return $this->hub->captureCheckIn(
            $this->slug,
            CheckInStatus::error(),
            null,
            $this->monitorConfig,
            $this->checkInId
        );
    }
}
