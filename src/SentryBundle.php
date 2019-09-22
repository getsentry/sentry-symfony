<?php

namespace Sentry\SentryBundle;

use Jean85\PrettyVersions;
use Sentry\SentrySdk;
use Sentry\State\Hub;
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
        if (class_exists(SentrySdk::class)) {
            return SentrySdk::getCurrentHub();
        }

        return Hub::getCurrent();
    }

    /**
     * This method avoids deprecations with sentry/sentry:^2.2
     */
    public static function setCurrentHub(HubInterface $hub): void
    {
        if (class_exists(SentrySdk::class)) {
            SentrySdk::setCurrentHub($hub);
        }

        Hub::setCurrent($hub);
    }
}
