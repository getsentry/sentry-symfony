<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

@trigger_error(sprintf('The "%s" class is deprecated since version 4.1 and will be removed in 5.0. Use "%s" instead.', ConsoleCommandListener::class, ConsoleListener::class), \E_USER_DEPRECATED);

/**
 * @deprecated since version 4.1, to be removed in 5.0
 */
final class ConsoleCommandListener extends ConsoleListener
{
}
