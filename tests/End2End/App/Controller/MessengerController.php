<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Psr\Log\LoggerInterface;
use Sentry\SentryBundle\Tests\End2End\App\Messenger\FooMessage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

class MessengerController
{
    /**
     * @var MessageBusInterface
     */
    private $messenger;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(MessageBusInterface $messenger, LoggerInterface $logger)
    {
        $this->messenger = $messenger;
        $this->logger = $logger;
    }

    public function dispatchMessage(): Response
    {
        $this->messenger->dispatch(new FooMessage());

        return new Response('Success');
    }

    public function dispatchUnrecoverableMessage(): Response
    {
        $this->logger->warning('Dispatch FooMessage');
        $this->messenger->dispatch(new FooMessage(false));

        return new Response('Success');
    }
}
