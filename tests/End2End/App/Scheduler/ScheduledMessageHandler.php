<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Scheduler;

class ScheduledMessageHandler
{
    public function __invoke(ScheduledMessage $message): void
    {
    }
}
