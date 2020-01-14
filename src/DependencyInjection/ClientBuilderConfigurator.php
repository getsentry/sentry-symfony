<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Sentry\ClientBuilderInterface;
use Sentry\SentryBundle\SentryBundle;

class ClientBuilderConfigurator
{
    public static function configure(ClientBuilderInterface $clientBuilder): void
    {
        $clientBuilder->setSdkIdentifier(SentryBundle::SDK_IDENTIFIER);
        $clientBuilder->setSdkVersion(SentryBundle::getSdkVersion());
    }
}
