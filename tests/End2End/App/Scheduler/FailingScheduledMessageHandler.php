<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Scheduler;

class FailingScheduledMessageHandler
{
    public function __invoke(FailingScheduledMessage $message): void
    {
        throw new \RuntimeException('This is an intentional failure while handling a scheduled message');
    }
}
