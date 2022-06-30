<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Sentry\SentryBundle\Tests\End2End\App\Messenger\FooMessage;
use Symfony\Component\HttpFoundation\Request;
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

    public function dispatchMessage(Request $request): Response
    {
        $query = $request->query->all();
        $this->messenger->dispatch(new FooMessage(true, $query));

        return new Response('Success ');
    }

    public function dispatchUnrecoverableMessage(): Response
    {
        $this->messenger->dispatch(new FooMessage(false));

        return new Response('Success');
    }
}
