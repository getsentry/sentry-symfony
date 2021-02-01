<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use Sentry\SentryBundle\EventListener\ConsoleListener;

final class ConsoleListenerTest extends AbstractConsoleListenerTest
{
    /**
     * {@inheritdoc}
     */
    protected static function getListenerClass(): string
    {
        return ConsoleListener::class;
    }
}
