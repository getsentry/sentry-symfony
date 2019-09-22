<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\Options;
use Sentry\SentryBundle\Test\DependencyInjection\ConfigurationTest;

abstract class BaseTestCase extends TestCase
{
    public const SUPPORTED_SENTRY_OPTIONS_COUNT = 23;

    protected function classSerializersAreSupported(): bool
    {
        try {
            new Options(['class_serializers' => []]);

            return true;
        } catch (\Throwable $throwable) {
            return false;
        }
    }

    protected function getSupportedOptionsCount(): int
    {
        if ($this->classSerializersAreSupported()) {
            return ConfigurationTest::SUPPORTED_SENTRY_OPTIONS_COUNT + 1;
        }

        return ConfigurationTest::SUPPORTED_SENTRY_OPTIONS_COUNT;
    }
}
