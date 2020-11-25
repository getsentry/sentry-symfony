<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

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
}
