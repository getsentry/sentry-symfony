<?php

declare(strict_types=1);

namespace Sentry\SentryBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SentryBundle extends Bundle
{
    public const SDK_IDENTIFIER = 'sentry.php.symfony';
}
