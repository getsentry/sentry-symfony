<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver\Result;

if (interface_exists(Result::class)) {
    interface ResultInterface extends Result
    {
    }
} else {
    class_alias(StatementInterface::class, __NAMESPACE__ . '\Result');
}
