<?php

namespace Sentry\SentryBundle\Tests\End2End\App\Callback;

use Sentry\Logs\Log;

class BeforeSendLogCallback
{

    public function getCallback(): callable
    {
        return function (Log $log): ?Log {
            if ($log->getBody() === "before_send_log") {
                return null;
            }
            return $log;
        };
    }

}
