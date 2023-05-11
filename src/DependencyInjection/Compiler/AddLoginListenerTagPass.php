<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Compiler;

use Sentry\SentryBundle\EventListener\LoginListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Security\Core\Event\AuthenticationSuccessEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final class AddLoginListenerTagPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container): void
    {
        $listenerDefinition = $container->getDefinition(LoginListener::class);

        if (!class_exists(LoginSuccessEvent::class)) {
            $listenerDefinition->addTag('kernel.event_listener', [
                'event' => AuthenticationSuccessEvent::class,
                'method' => 'handleAuthenticationSuccessEvent',
            ]);
        }
    }
}
