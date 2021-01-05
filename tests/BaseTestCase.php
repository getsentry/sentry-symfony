<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests;

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
}
