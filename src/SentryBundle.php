<?php

namespace Sentry\SentryBundle;

use Jean85\PrettyVersions;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SentryBundle extends Bundle
{
    public const SDK_IDENTIFIER = 'sentry.php.symfony';

    public static function getSdkVersion(): string
    {
        return PrettyVersions::getVersion('sentry/sentry-symfony')
            ->getPrettyVersion();
    }
}
