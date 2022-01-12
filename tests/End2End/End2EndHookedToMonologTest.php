<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\KernelHookedToMonolog;

class End2EndHookedToMonologTest extends End2EndTest
{
    protected static function getKernelClass(): string
    {
        return KernelHookedToMonolog::class;
    }
}
