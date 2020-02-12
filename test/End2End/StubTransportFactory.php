<?php

namespace Sentry\SentryBundle\Test\End2End;

use Sentry\Event;
use Sentry\Options;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

class StubTransportFactory implements TransportFactoryInterface
{
    public function create(Options $options): TransportInterface
    {
        return new class() implements TransportInterface {
            public function send(Event $event): ?string
            {
                touch(End2EndTest::SENT_EVENTS_LOG);

                file_put_contents(
                    End2EndTest::SENT_EVENTS_LOG, 
                    $event->getId() . ': ' . $event->getExceptions()[0]['value'] . PHP_EOL, 
                    FILE_APPEND
                );

                return $event->getId();
            }
        };
    }
}
