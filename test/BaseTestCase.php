<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Options;

abstract class BaseTestCase extends TestCase
{
    protected function classSerializersAreSupported(): bool
    {
        return $this->optionIsSupported('class_serializers', []);
    }

    protected function maxRequestBodySizeIsSupported(): bool
    {
        return $this->optionIsSupported('max_request_body_size', 'none');
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

    private function optionIsSupported(string $name, $defaultValue): bool
    {
        try {
            new Options([$name => $defaultValue]);

            return true;
        } catch (\Throwable $throwable) {
            return false;
        }
    }
}
