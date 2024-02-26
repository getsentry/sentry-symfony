<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\ResultStatement;
use Doctrine\DBAL\Platforms\AbstractPlatform\VersionAwarePlatformDriver;
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
            && interface_exists(ResultStatement::class);
    }

    protected static function isDoctrineDBALVersion3Installed(): bool
    {
        return self::isDoctrineDBALInstalled()
            && !self::isDoctrineDBALVersion2Installed()
            && interface_exists(VersionAwarePlatformDriver::class);
    }

    protected static function isDoctrineDBALVersion4Installed(): bool
    {
        return self::isDoctrineDBALInstalled()
            && !self::isDoctrineDBALVersion2Installed()
            && !self::isDoctrineDBALVersion3Installed()
            && !interface_exists(VersionAwarePlatformDriver::class);
    }

    protected static function isDoctrineBundlePackageInstalled(): bool
    {
        return class_exists(DoctrineBundle::class);
    }
}
