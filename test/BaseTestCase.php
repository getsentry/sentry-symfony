<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Options;

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
}
