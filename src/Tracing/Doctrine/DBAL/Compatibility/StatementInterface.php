<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Doctrine\DBAL\Compatibility;

use Doctrine\DBAL\Driver\Statement;

if (interface_exists(Statement::class)) {
    class_alias(Statement::class, __NAMESPACE__ . '\StatementInterface');
} else {
    /**
     * @internal
     */
    interface StatementInterface
    {
    }
}
