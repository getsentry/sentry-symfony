<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Messenger;

use Sentry\SentrySdk;
use Sentry\State\Scope;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class FooMessageHandler implements MessageHandlerInterface
{
    public function __invoke(FooMessage $message): void
    {
        $scopeData = $message->getScopeData();
        if (null !== $scopeData) {
            SentrySdk::getCurrentHub()->withScope(function (Scope $scope) use ($scopeData): void {
                $scope->setContext('testContext', $scopeData);
            });
        }

        if (!$message->shouldRetry()) {
            throw new class() extends \Exception implements UnrecoverableExceptionInterface { };
        }

        throw new \Exception('This is an intentional failure while handling a message of class ' . \get_class($message));
    }
}
