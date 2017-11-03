<?php

namespace Sentry\SentryBundle;

use Jean85\PrettyVersions;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SentryBundle extends Bundle
{
    public static function getVersion(): string
    {
        return PrettyVersions::getVersion('sentry/sentry-symfony')
            ->getPrettyVersion();
    }
}
