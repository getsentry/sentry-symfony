<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Compiler\AddLoginListenerTagPass;
use Sentry\SentryBundle\EventListener\LoginListener;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Core\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class AddLoginListenerTagPassTest extends TestCase
{
    public function testProcess(): void
    {
        if (class_exists(LoginSuccessEvent::class)) {
            $this->markTestSkipped('This test is incompatible with versions of Symfony where the LoginSuccessEvent event exists.');
        }

        $container = new ContainerBuilder();
        $container->register(LoginListener::class)->setPublic(true);
        $container->addCompilerPass(new AddLoginListenerTagPass());
        $container->compile();

        $listenerDefinition = $container->getDefinition(LoginListener::class);

        $this->assertSame([['event' => AuthenticationSuccessEvent::class, 'method' => 'handleAuthenticationSuccessEvent']], $listenerDefinition->getTag('kernel.event_listener'));
    }
}
