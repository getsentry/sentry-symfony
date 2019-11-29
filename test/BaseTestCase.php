<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Options;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\HubInterface;

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
        $count = 23;

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
}
