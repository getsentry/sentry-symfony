<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\Fixtures;

class TestIntegrationForInvokable implements \Sentry\Integration\IntegrationInterface
{
    public function setupOnce(): void
    {
    }
}
