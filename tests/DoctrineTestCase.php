<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests;

use Doctrine\DBAL\Driver\ResultStatement;
use PHPUnit\Framework\TestCase;

abstract class DoctrineTestCase extends TestCase
{
    protected function isDoctrineDBALVersion3Installed(): bool
    {
        return !interface_exists(ResultStatement::class);
    }
}
