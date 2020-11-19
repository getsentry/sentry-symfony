<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelInterface;

// Trait is available in phpspec/prophecy-phpunit:2.0 which requires at least PHP 7.3
if (trait_exists(ProphecyTrait::class)) {
    abstract class BaseProphecyTestCase extends TestCase
    {
        use ProphecyTrait;
    }
} else {
    abstract class BaseProphecyTestCase extends TestCase
    {
    }
}

abstract class BaseTestCase extends BaseProphecyTestCase
{
    protected function getSupportedOptionsCount(): int
    {
        return 27;
    }

    protected function createRequestEvent(Request $request = null, int $type = KernelInterface::MASTER_REQUEST)
    {
        if ($request === null) {
            $request = $this->prophesize(Request::class)->reveal();
        }

        if (class_exists(RequestEvent::class)) {
            $event = new RequestEvent(
                $this->prophesize(KernelInterface::class)->reveal(),
                $request,
                $type
            );
        } else {
            $event = new GetResponseEvent(
                $this->prophesize(KernelInterface::class)->reveal(),
                $request,
                $type,
                $this->prophesize(Response::class)->reveal()
            );
        }

        return $event;
    }
}
