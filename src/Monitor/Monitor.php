<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monitor;

use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\Event;
use Sentry\MonitorConfig;
use Sentry\State\HubInterface;

class Monitor implements MonitorInterface
{
    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var MonitorConfig
     */
    private $monitorConfig;
    /**
     * @var string
     */
    private $environment;
    /**
     * @var string
     */
    private $slug;
    /**
     * @var string|null
     */
    private $release;

    public function __construct(
        HubInterface $hub,
        MonitorConfig $monitorConfig,
        string $slug,
        string $environment,
        string $release = null
    ) {
        $this->monitorConfig = $monitorConfig;
        $this->environment = $environment;
        $this->slug = $slug;
        $this->hub = $hub;
        $this->release = $release;
    }

    public function inProgress(CheckIn $previous = null): CheckIn
    {
        $event = Event::createCheckIn();
        $checkIn = new CheckIn(
            $this->slug,
            CheckInStatus::inProgress(),
            $previous ? $previous->getId() : null,
            $this->release,
            $this->environment,
            null,
            $this->monitorConfig
        );
        $event->setCheckIn($checkIn);
        $this->hub->captureEvent($event);

        return $checkIn;
    }

    public function error(CheckIn $previous = null): CheckIn
    {
        $event = Event::createCheckIn();
        $checkIn = new CheckIn(
            $this->slug,
            CheckInStatus::error(),
            $previous ? $previous->getId() : null,
            $this->release,
            $this->environment,
            null,
            $this->monitorConfig
        );
        $event->setCheckIn($checkIn);
        $this->hub->captureEvent($event);

        return $checkIn;
    }

    public function ok(CheckIn $previous = null): CheckIn
    {
        $event = Event::createCheckIn();
        $checkIn = new CheckIn(
            $this->slug,
            CheckInStatus::ok(),
            $previous ? $previous->getId() : null,
            $this->release,
            $this->environment,
            null,
            $this->monitorConfig
        );
        $event->setCheckIn($checkIn);
        $this->hub->captureEvent($event);

        return $checkIn;
    }
}
