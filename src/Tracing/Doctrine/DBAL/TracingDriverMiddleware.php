<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility\DriverInterface;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility\MiddlewareInterface;
use Sentry\State\HubInterface;

/**
 * This middleware wraps a {@see \Doctrine\DBAL\Driver} instance into one that
 * supports the distributed tracing feature of Sentry.
 */
final class TracingDriverMiddleware implements MiddlewareInterface
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
    public function wrap(DriverInterface $driver): DriverInterface
    {
        return new TracingDriver($this->hub, $driver);
    }
}
