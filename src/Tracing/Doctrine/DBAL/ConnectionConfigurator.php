<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Connection;

/**
 * @internal
 */
final class ConnectionConfigurator
{
    /**
     * @var TracingDriverMiddleware
     */
    private $tracingDriverMiddleware;

    /**
     * Constructor.
     *
     * @param TracingDriverMiddleware $tracingDriverMiddleware The driver middleware
     */
    public function __construct(TracingDriverMiddleware $tracingDriverMiddleware)
    {
        $this->tracingDriverMiddleware = $tracingDriverMiddleware;
    }

    /**
     * Configures the given connection by wrapping its driver into an instance
     * of the {@see TracingDriver} class. This is done using the reflection,
     * and as such should be limited only to the versions of Doctrine DBAL that
     * are lower than 3.0. Since 3.0 onwards, the concept of driver middlewares
     * has been introduced which allows the same thing we're doing here, but in
     * a more appropriate and "legal" way.
     *
     * @param Connection $connection The connection to configure
     */
    public function configure(Connection $connection): void
    {
        $reflectionProperty = new \ReflectionProperty($connection, '_driver');
        if (\PHP_VERSION_ID < 80100) {
            $reflectionProperty->setAccessible(true);
        }
        $reflectionProperty->setValue($connection, $this->tracingDriverMiddleware->wrap($reflectionProperty->getValue($connection)));
        if (\PHP_VERSION_ID < 80100) {
            $reflectionProperty->setAccessible(false);
        }
    }
}
