<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use Sentry\Event;
use Sentry\Options;
use Sentry\Response;
use Sentry\ResponseStatus;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

class StubTransportFactory implements TransportFactoryInterface
{
    public const SEPARATOR = '###';

    public function create(Options $options): TransportInterface
    {
        return new class() implements TransportInterface {
            public function send(Event $event): PromiseInterface
            {
                touch(End2EndTest::SENT_EVENTS_LOG);

                if ($event->getMessage()) {
                    $message = $event->getMessage();
                } elseif ($event->getExceptions()) {
                    $message = $event->getExceptions()[0]->getValue();
                } else {
                    $message = 'NO MESSAGE NOR EXCEPTIONS';
                }

                file_put_contents(
                    End2EndTest::SENT_EVENTS_LOG,
                    $event->getId() . ': ' . $message . \PHP_EOL . StubTransportFactory::SEPARATOR . \PHP_EOL,
                    \FILE_APPEND
                );

                return new FulfilledPromise(new Response(ResponseStatus::success(), $event));
            }

            public function close(?int $timeout = null): PromiseInterface
            {
                return new FulfilledPromise(true);
            }
        };
    }
}
