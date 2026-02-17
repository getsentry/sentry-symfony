<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Callback;

use Sentry\Logs\Log;

class BeforeSendLogCallback
{
    public function getCallback(): callable
    {
        return static function (Log $log): ?Log {
            if ('before_send_log' === $log->getBody()) {
                return null;
            }

            return $log;
        };
    }
}
