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
     * @var IntegrationInterface[]|callable|mixed
     */
    private $userConfig;

    /**
     * @var bool
     */
    private $registerErrorHandler;

    /**
     * @param IntegrationInterface[]|callable $userConfig Array of integrations or a callable that filters/returns integrations
     */
    public function __construct($userConfig, bool $registerErrorHandler)
    {
        $this->userConfig = $userConfig;
        $this->registerErrorHandler = $registerErrorHandler;
    }

    /**
     * @param IntegrationInterface[] $defaultIntegrations
     *
     * @return IntegrationInterface[]
     *
     * @see IntegrationRegistry::getIntegrationsToSetup()
     */
    public function __invoke(array $defaultIntegrations): array
    {
        $filteredDefaults = [];
        foreach ($defaultIntegrations as $defaultIntegration) {
            $integrationClassName = \get_class($defaultIntegration);

            if (!$this->registerErrorHandler && isset(self::ERROR_HANDLER_INTEGRATIONS[$integrationClassName])) {
                continue;
            }

            $filteredDefaults[] = $defaultIntegration;
        }

        if (\is_callable($this->userConfig)) {
            $result = ($this->userConfig)($filteredDefaults);

            if (!\is_array($result)) {
                throw new \UnexpectedValueException(\sprintf('Expected the callable set for the user integrations to return a list of integrations. Got: "%s".', get_debug_type($result)));
            }

            return $result;
        }

        $integrations = [];
        /** @var IntegrationInterface[] $userIntegrations */
        $userIntegrations = is_array($this->userConfig) ? $this->userConfig : [];
        $userIntegrationsClasses = array_map('get_class', $userIntegrations);
        $pickedIntegrationsClasses = [];

        foreach ($filteredDefaults as $defaultIntegration) {
            $integrationClassName = \get_class($defaultIntegration);

            if (!\in_array($integrationClassName, $userIntegrationsClasses, true) && !isset($pickedIntegrationsClasses[$integrationClassName])) {
                $integrations[] = $defaultIntegration;
                $pickedIntegrationsClasses[$integrationClassName] = true;
            }
        }

        foreach ($userIntegrations as $userIntegration) {
            $integrationClassName = \get_class($userIntegration);

            if (!isset($pickedIntegrationsClasses[$integrationClassName])) {
                $integrations[] = $userIntegration;
                $pickedIntegrationsClasses[$integrationClassName] = true;
            }
        }

        return $integrations;
    }
}
