<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility\MiddlewareInterface;
use Sentry\State\HubInterface;

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
     * @param HubInterface|TracingDriverConnectionFactoryInterface $hubOrConnectionFactory The current hub (deprecated) or the connection factory
     */
    public function __construct($hubOrConnectionFactory)
    {
        if ($hubOrConnectionFactory instanceof TracingDriverConnectionFactoryInterface) {
            $this->connectionFactory = $hubOrConnectionFactory;
        } elseif ($hubOrConnectionFactory instanceof HubInterface) {
            @trigger_error(sprintf('Not passing an instance of the "%s" interface as argument of the constructor is deprecated since version 4.2 and will not work since version 5.0.', TracingDriverConnectionFactoryInterface::class), \E_USER_DEPRECATED);

            $this->connectionFactory = new TracingDriverConnectionFactory($hubOrConnectionFactory);
        } else {
            throw new \InvalidArgumentException(sprintf('The constructor requires either an instance of the "%s" interface or an instance of the "%s" interface.', HubInterface::class, TracingDriverConnectionFactoryInterface::class));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function wrap(Driver $driver): Driver
    {
        return new TracingDriver($this->connectionFactory, $driver);
    }
}
