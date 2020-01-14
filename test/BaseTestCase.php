<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelInterface;

abstract class BaseTestCase extends TestCase
{
    protected function classSerializersAreSupported(): bool
    {
        return method_exists(Options::class, 'getClassSerializers');
    }

    protected function maxRequestBodySizeIsSupported(): bool
    {
        return method_exists(Options::class, 'getMaxRequestBodySize');
    }

    protected function getSupportedOptionsCount(): int
    {
        $count = 24;

        if ($this->classSerializersAreSupported()) {
            ++$count;
        }

        if ($this->maxRequestBodySizeIsSupported()) {
            ++$count;
        }

        return $count;
    }

    protected function setCurrentHub(HubInterface $hub): void
    {
        if (class_exists(SentrySdk::class)) {
            SentrySdk::setCurrentHub($hub);
        } else {
            Hub::setCurrent($hub);
        }
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
