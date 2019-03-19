<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Sentry\ClientBuilderInterface;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\SentryBundle\SentryBundle;

class ClientBuilderConfigurator
{
    public static function configure(ClientBuilderInterface $clientBuilder): void
    {
        $clientBuilder->setSdkIdentifier(SentryBundle::SDK_IDENTIFIER);
        $clientBuilder->setSdkVersion(SentryBundle::getSdkVersion());

        $options = $clientBuilder->getOptions();
        if (! $options->hasDefaultIntegrations()) {
            return;
        }

        $integrations = $options->getIntegrations();
        $options->setIntegrations(array_filter($integrations, function (IntegrationInterface $integration) {
            if ($integration instanceof ErrorListenerIntegration) {
                return false;
            }

            if ($integration instanceof ExceptionListenerIntegration) {
                return false;
            }

            return true;
        }));
    }
}
