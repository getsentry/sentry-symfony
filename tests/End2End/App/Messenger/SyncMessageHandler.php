<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Messenger;

use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

final class SyncMessageHandler implements MessageHandlerInterface
{
    public function __invoke(SyncMessage $message): void
    {
        throw new IgnorableException('This is an intentional failure while handling a message of class ' . \get_class($message));
    }
}
