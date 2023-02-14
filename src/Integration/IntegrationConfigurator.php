<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Integration;

use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\IntegrationInterface;

/**
 * @internal
 */
final class IntegrationConfigurator
{
    private const ERROR_HANDLER_INTEGRATIONS = [
        ErrorListenerIntegration::class => true,
        ExceptionListenerIntegration::class => true,
        FatalErrorListenerIntegration::class => true,
    ];

    /**
     * @var IntegrationInterface[]
     */
    private $userIntegrations;

    /**
     * @var bool
     */
    private $registerErrorHandler;

    /**
     * @param IntegrationInterface[] $userIntegrations
     */
    public function __construct(array $userIntegrations, bool $registerErrorHandler)
    {
        $this->userIntegrations = $userIntegrations;
        $this->registerErrorHandler = $registerErrorHandler;
    }

    /**
     * @see IntegrationRegistry::getIntegrationsToSetup()
     *
     * @param IntegrationInterface[] $defaultIntegrations
     *
     * @return IntegrationInterface[]
     */
    public function __invoke(array $defaultIntegrations): array
    {
        $integrations = [];

        $userIntegrationsClasses = array_map('get_class', $this->userIntegrations);
        $pickedIntegrationsClasses = [];

        foreach ($defaultIntegrations as $defaultIntegration) {
            $integrationClassName = \get_class($defaultIntegration);

            if (!$this->registerErrorHandler && isset(self::ERROR_HANDLER_INTEGRATIONS[$integrationClassName])) {
                continue;
            }

            if (!\in_array($integrationClassName, $userIntegrationsClasses, true) && !isset($pickedIntegrationsClasses[$integrationClassName])) {
                $integrations[] = $defaultIntegration;
                $pickedIntegrationsClasses[$integrationClassName] = true;
            }
        }

        foreach ($this->userIntegrations as $userIntegration) {
            $integrationClassName = \get_class($userIntegration);

            if (!isset($pickedIntegrationsClasses[$integrationClassName])) {
                $integrations[] = $userIntegration;
                $pickedIntegrationsClasses[$integrationClassName] = true;
            }
        }

        return $integrations;
    }
}
