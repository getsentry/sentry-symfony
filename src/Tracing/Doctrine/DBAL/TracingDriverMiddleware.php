<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Sentry\State\HubInterface;

/**
 * This middleware wraps a {@see Driver} instance into one that
 * supports the distributed tracing feature of Sentry.
 */
final class TracingDriverMiddleware implements Middleware
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * Constructor.
     *
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * {@inheritdoc}
     */
    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($this->hub, $driver);
    }
}
