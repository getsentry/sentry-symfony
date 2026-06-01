<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Callback;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\Exception\SilencedErrorException;

class IgnoreSilencedDeprecationBeforeSendCallback
{
    public function getCallback(): callable
    {
        return static function (Event $event, ?EventHint $hint): ?Event {
            $exception = null !== $hint ? $hint->exception : null;

            if ($exception instanceof SilencedErrorException && \in_array($exception->getSeverity(), [\E_DEPRECATED, \E_USER_DEPRECATED], true)) {
                return null;
            }

            return $event;
        };
    }
}
