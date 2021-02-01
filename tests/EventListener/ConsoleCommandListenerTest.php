<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use Sentry\SentryBundle\EventListener\ConsoleCommandListener;

/**
 * @group legacy
 *
 * @deprecated since version 4.1, to be removed in 5.0
 */
final class ConsoleCommandListenerTest extends AbstractConsoleListenerTest
{
    /**
     * {@inheritdoc}
     */
    protected static function getListenerClass(): string
    {
        return ConsoleCommandListener::class;
    }
}
