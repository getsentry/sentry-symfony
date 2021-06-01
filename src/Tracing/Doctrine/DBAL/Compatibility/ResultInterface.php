<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver\Result;

if (!interface_exists(Result::class)) {
    class_alias(StatementInterface::class, __NAMESPACE__ . '\Result');
} else {
    interface ResultInterface extends Result
    {
    }
}
