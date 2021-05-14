<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Sentry\SentryBundle\Tests\End2End\App\Messenger\FooMessage;
use Sentry\SentryBundle\Tests\End2End\App\Messenger\SyncMessage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

class MessengerController
{
    /**
     * @var MessageBusInterface
     */
    private $messenger;

    public function __construct(MessageBusInterface $messenger)
    {
        $this->messenger = $messenger;
    }

    public function dispatchMessage(): Response
    {
        $this->messenger->dispatch(new FooMessage());

        return new Response('Success');
    }

    public function dispatchUnrecoverableMessage(): Response
    {
        $this->messenger->dispatch(new FooMessage(false));

        return new Response('Success');
    }

    public function dispatchUnrecoverableSyncMessage(): Response
    {
        $this->messenger->dispatch(new SyncMessage());

        return new Response('Success');
    }
}
