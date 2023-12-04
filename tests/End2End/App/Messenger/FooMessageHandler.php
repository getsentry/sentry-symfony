<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Messenger;

use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

class FooMessageHandler
{
    public function __invoke(FooMessage $message): void
    {
        if (!$message->shouldRetry()) {
            throw new class() extends \Exception implements UnrecoverableExceptionInterface { };
        }

        throw new \Exception('This is an intentional failure while handling a message of class ' . \get_class($message));
    }
}
