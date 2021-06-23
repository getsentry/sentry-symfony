<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use PHPUnit\Framework\TestCase;

abstract class DoctrineTestCase extends TestCase
{
    protected static function isDoctrineDBALInstalled(): bool
    {
        return interface_exists(Driver::class);
    }

    protected static function isDoctrineDBALVersion2Installed(): bool
    {
        return self::isDoctrineDBALInstalled()
            && ! self::isDoctrineDBALVersion3Installed();
    }

    protected static function isDoctrineDBALVersion3Installed(): bool
    {
        return interface_exists(Middleware::class);
    }

    protected static function isDoctrineBundlePackageInstalled(): bool
    {
        return class_exists(DoctrineBundle::class);
    }
}
