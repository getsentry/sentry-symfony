<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Event;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

class StubTransport implements TransportInterface
{
    public const SEPARATOR = '###';

    /**
     * @var Event[]
     */
    public static $events = [];

    public function send(Event $event): Result
    {
        self::$events[] = $event;
        touch(End2EndTest::SENT_EVENTS_LOG);

        if ($event->getMessage()) {
            $message = $event->getMessage();
        } elseif ($event->getExceptions()) {
            $message = $event->getExceptions()[0]->getValue();
        } elseif ($event->getTransaction()) {
            $message = 'TRACING: ' . $event->getTransaction();
            foreach ($event->getSpans() as $i => $span) {
                $message .= \PHP_EOL . $i . ') ' . $span->getDescription();
            }
        } else {
            $message = 'NO MESSAGE NOR EXCEPTIONS';
        }

        file_put_contents(
            End2EndTest::SENT_EVENTS_LOG,
            $event->getId() . ': ' . $message . \PHP_EOL . self::SEPARATOR . \PHP_EOL,
            \FILE_APPEND
        );

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}
