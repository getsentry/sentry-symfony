<?php

namespace Sentry\SentryBundle;

use Jean85\PrettyVersions;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class SentryBundle extends Bundle
{
    public const SDK_IDENTIFIER = 'sentry.php.symfony';

    public static function getSdkVersion(): string
    {
        return PrettyVersions::getVersion('sentry/sentry-symfony')
            ->getPrettyVersion();
    }

    /**
     * This method avoids deprecations with sentry/sentry:^2.2
     */
    public static function getCurrentHub(): HubInterface
    {
        return SentrySdk::getCurrentHub();
    }

    /**
     * This method avoids deprecations with sentry/sentry:^2.2
     */
    public static function setCurrentHub(HubInterface $hub): void
    {
        SentrySdk::setCurrentHub($hub);
    }
}
