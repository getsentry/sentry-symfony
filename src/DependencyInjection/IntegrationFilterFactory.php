<?php

namespace Sentry\SentryBundle\DependencyInjection;

use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\IntegrationInterface;

class IntegrationFilterFactory
{
    /**
     * @param IntegrationInterface[] $integrationsFromConfiguration
     */
    public static function create(array $integrationsFromConfiguration): callable
    {
        return function (array $integrations) use ($integrationsFromConfiguration) {
            $allIntegrations = array_merge($integrations, $integrationsFromConfiguration);

            return array_filter(
                $allIntegrations,
                static function (IntegrationInterface $integration): bool {
                    if ($integration instanceof ErrorListenerIntegration) {
                        return false;
                    }

                    if ($integration instanceof ExceptionListenerIntegration) {
                        return false;
                    }

                    return true;
                }
            );
        };
    }
}
