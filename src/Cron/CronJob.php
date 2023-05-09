<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Cron;

use Sentry\CheckIn;
use Sentry\CheckInStatus;
use Sentry\Event;
use Sentry\State\HubInterface;

class CronJob implements CronJobInterface
{
    /**
     * @var string
     */
    private $environment;
    /**
     * @var string
     */
    private $slug;
    /**
     * @var HubInterface
     */
    private $hub;
    /**
     * @var string|null
     */
    private $release;

    public function __construct(HubInterface $hub, string $slug, string $environment, ?string $release = null)
    {
        $this->environment = $environment;
        $this->slug = $slug;
        $this->hub = $hub;
        $this->release = $release;
    }

    public function inProgress(?CheckIn $previous = null): CheckIn
    {
        $event = Event::createCheckIn();
        $checkIn = new CheckIn(
            $this->slug,
            CheckInStatus::inProgress(),
            $previous ? $previous->getId() : null,
            $this->release,
            $this->environment
        );
        $event->setCheckIn($checkIn);
        $this->hub->captureEvent($event);

        return $checkIn;
    }

    public function error(?CheckIn $previous = null): CheckIn
    {
        $event = Event::createCheckIn();
        $checkIn = new CheckIn(
            $this->slug,
            CheckInStatus::error(),
            $previous ? $previous->getId() : null,
            $this->release,
            $this->environment
        );
        $event->setCheckIn($checkIn);
        $this->hub->captureEvent($event);

        return $checkIn;
    }

    public function ok(?CheckIn $previous = null): CheckIn
    {
        $event = Event::createCheckIn();
        $checkIn = new CheckIn(
            $this->slug,
            CheckInStatus::ok(),
            $previous ? $previous->getId() : null,
            $this->release,
            $this->environment
        );
        $event->setCheckIn($checkIn);
        $this->hub->captureEvent($event);

        return $checkIn;
    }
}
