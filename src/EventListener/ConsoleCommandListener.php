<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;

/**
 * @deprecated since version 4.1, to be removed in 5.0
 */
final class ConsoleCommandListener extends ConsoleListener
{
    public function __construct(HubInterface $hub, bool $captureErrors = true)
    {
        parent::__construct($hub, $captureErrors);

        @trigger_error(sprintf('The "%s" class is deprecated since version 4.1 and will be removed in 5.0. Use "%s" instead.', self::class, ConsoleListener::class), \E_USER_DEPRECATED);
    }
}
