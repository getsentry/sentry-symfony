<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver as DriverInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriver;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware;
use Sentry\State\HubInterface;

final class TracingDriverMiddlewareTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var TracingDriverMiddleware
     */
    private $middleware;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->middleware = new TracingDriverMiddleware($this->hub);
    }

    public function testWrap(): void
    {
        $driver = $this->createMock(DriverInterface::class);

        $this->assertInstanceOf(TracingDriver::class, $this->middleware->wrap($driver));
    }
}