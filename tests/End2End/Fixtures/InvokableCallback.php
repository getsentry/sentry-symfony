<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\Fixtures;

use Sentry\Integration\IntegrationInterface;

final class InvokableCallback
{
    /**
     * @param IntegrationInterface[] $integrations
     *
     * @return IntegrationInterface[]
     */
    public function __invoke(array $integrations): array
    {
        $integrations[] = new TestIntegrationForInvokable();

        return $integrations;
    }
}
