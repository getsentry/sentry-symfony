<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility\MiddlewareInterface;

/**
 * This middleware wraps a {@see Driver} instance into one that
 * supports the distributed tracing feature of Sentry.
 *
 * @internal since version 4.2
 */
final class TracingDriverMiddleware implements MiddlewareInterface
{
    /**
     * @var TracingDriverConnectionFactoryInterface
     */
    private $connectionFactory;

    /**
     * Constructor.
     *
     * @param TracingDriverConnectionFactoryInterface $connectionFactory The connection factory
     */
    public function __construct(TracingDriverConnectionFactoryInterface $connectionFactory)
    {
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($this->connectionFactory, $driver);
    }
}
