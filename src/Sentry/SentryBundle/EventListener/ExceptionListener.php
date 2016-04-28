<?php

namespace Sentry\SentryBundle\EventListener;

use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExceptionListener
{
    public function __construct(\Raven_Client $client = null)
    {
        if (!$client) {
            $client = new \Raven_Client();
        }
        $this->client = $client;
    }

    public function setClient(\Raven_Client $client)
    {
        $this->client = $client;
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $exception = $event->getException();

        // dont capture HTTP responses
        if ($exception instanceof HttpException) {
            return;
        }

        $this->client->captureException($exception);
    }
}
