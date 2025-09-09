<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\Fixtures;

final class FactoryCallback
{
    public static function factory(): callable
    {
        return static function (array $integrations): array {
            $integrations[] = new TestIntegrationForFactory();

            return $integrations;
        };
    }
}
