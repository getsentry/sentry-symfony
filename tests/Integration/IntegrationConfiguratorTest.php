<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sentry\Integration\EnvironmentIntegration;
use Sentry\Integration\ErrorListenerIntegration;
use Sentry\Integration\ExceptionListenerIntegration;
use Sentry\Integration\FatalErrorListenerIntegration;
use Sentry\Integration\FrameContextifierIntegration;
use Sentry\Integration\IntegrationInterface;
use Sentry\Integration\ModulesIntegration;
use Sentry\Integration\RequestIntegration;
use Sentry\Integration\TransactionIntegration;
use Sentry\SentryBundle\Integration\IntegrationConfigurator;

final class IntegrationConfiguratorTest extends TestCase
{
    /**
     * @dataProvider integrationsDataProvider
     *
     * @param IntegrationInterface[]|callable $userIntegrations
     * @param IntegrationInterface[]          $defaultIntegrations
     * @param IntegrationInterface[]          $expectedIntegrations
     */
    public function testIntegrationConfigurator(
        $userIntegrations,
        bool $registerErrorHandler,
        array $defaultIntegrations,
        array $expectedIntegrations
    ): void {
        $integrationConfigurator = new IntegrationConfigurator($userIntegrations, $registerErrorHandler);

        $this->assertSame($expectedIntegrations, $integrationConfigurator($defaultIntegrations));
    }

    /**
     * @return iterable<array{0: IntegrationInterface[]|callable, 1: bool, 2: IntegrationInterface[], 3: IntegrationInterface[]}>
     */
    public function integrationsDataProvider(): iterable
    {
        $exceptionListenerIntegration = new ExceptionListenerIntegration();
        $errorListenerIntegration = new ErrorListenerIntegration();
        $fatalErrorListenerIntegration = new FatalErrorListenerIntegration();
        $requestIntegration = new RequestIntegration();
        $transactionIntegration = new TransactionIntegration();
        $frameContextifierIntegration = new FrameContextifierIntegration();
        $environmentIntegration = new EnvironmentIntegration();
        $modulesIntegration = new ModulesIntegration();

        $userIntegration1 = new class implements IntegrationInterface {
            public function setupOnce(): void
            {
            }
        };
        $userRequestIntegration = new RequestIntegration();

        yield 'Default integrations, register error handler true and no user integrations' => [
            [],
            true,
            [
                $exceptionListenerIntegration,
                $errorListenerIntegration,
                $fatalErrorListenerIntegration,
                $requestIntegration,
                $transactionIntegration,
                $frameContextifierIntegration,
                $environmentIntegration,
                $modulesIntegration,
            ],
            [
                $exceptionListenerIntegration,
                $errorListenerIntegration,
                $fatalErrorListenerIntegration,
                $requestIntegration,
                $transactionIntegration,
                $frameContextifierIntegration,
                $environmentIntegration,
                $modulesIntegration,
            ],
        ];

        yield 'Default integrations, register error handler false and no user integrations' => [
            [],
            false,
            [
                $exceptionListenerIntegration,
                $errorListenerIntegration,
                $fatalErrorListenerIntegration,
                $requestIntegration,
                $transactionIntegration,
                $frameContextifierIntegration,
                $environmentIntegration,
                $modulesIntegration,
            ],
            [
                $requestIntegration,
                $transactionIntegration,
                $frameContextifierIntegration,
                $environmentIntegration,
                $modulesIntegration,
            ],
        ];

        yield 'Default integrations, register error handler true and some user integrations, one of which is also a default integration' => [
            [
                $userIntegration1,
                $userRequestIntegration,
            ],
            true,
            [
                $exceptionListenerIntegration,
                $errorListenerIntegration,
                $fatalErrorListenerIntegration,
                $requestIntegration,
                $transactionIntegration,
                $frameContextifierIntegration,
                $environmentIntegration,
                $modulesIntegration,
            ],
            [
                $exceptionListenerIntegration,
                $errorListenerIntegration,
                $fatalErrorListenerIntegration,
                $transactionIntegration,
                $frameContextifierIntegration,
                $environmentIntegration,
                $modulesIntegration,
                $userIntegration1,
                $userRequestIntegration,
            ],
        ];

        yield 'Default integrations, register error handler false and some user integrations, one of which is also a default integration' => [
            [
                $userIntegration1,
                $userRequestIntegration,
            ],
            false,
            [
                $exceptionListenerIntegration,
                $errorListenerIntegration,
                $fatalErrorListenerIntegration,
                $requestIntegration,
                $transactionIntegration,
                $frameContextifierIntegration,
                $environmentIntegration,
                $modulesIntegration,
            ],
            [
                $transactionIntegration,
                $frameContextifierIntegration,
                $environmentIntegration,
                $modulesIntegration,
                $userIntegration1,
                $userRequestIntegration,
            ],
        ];

        yield 'No default integrations and some user integrations are repeated twice' => [
            [
                $userIntegration1,
                $userRequestIntegration,
                $userIntegration1,
            ],
            true,
            [],
            [
                $userIntegration1,
                $userRequestIntegration,
            ],
        ];

        yield 'User provided callable receives filtered defaults when error handler disabled' => [
            static function (array $defaults) use ($userIntegration1): array {
                $classes = array_map('get_class', $defaults);
                self::assertContains(RequestIntegration::class, $classes);
                self::assertNotContains(ExceptionListenerIntegration::class, $classes);
                self::assertNotContains(ErrorListenerIntegration::class, $classes);
                self::assertNotContains(FatalErrorListenerIntegration::class, $classes);

                return [$userIntegration1];
            },
            false,
            [
                $exceptionListenerIntegration,
                $errorListenerIntegration,
                $fatalErrorListenerIntegration,
                $requestIntegration,
                $transactionIntegration,
            ],
            [
                $userIntegration1,
            ],
        ];

        yield 'User provided callable receives all defaults when error handler enabled' => [
            static function (array $defaults) use ($userIntegration1): array {
                $classes = array_map('get_class', $defaults);
                self::assertContains(ExceptionListenerIntegration::class, $classes);
                self::assertContains(ErrorListenerIntegration::class, $classes);
                self::assertContains(FatalErrorListenerIntegration::class, $classes);
                self::assertContains(RequestIntegration::class, $classes);

                return [$userIntegration1];
            },
            true,
            [
                $exceptionListenerIntegration,
                $errorListenerIntegration,
                $fatalErrorListenerIntegration,
                $requestIntegration,
                $transactionIntegration,
            ],
            [
                $userIntegration1,
            ],
        ];
    }
}
