<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class BaseTestCase extends TestCase
{
    protected function getSupportedOptionsCount(): int
    {
        return 26;
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
