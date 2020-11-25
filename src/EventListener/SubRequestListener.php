<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Kernel;

if (version_compare(Kernel::VERSION, '4.3.0', '>=')) {
    if (! class_exists(SubRequestListenerRequestEvent::class, false)) {
        class_alias(RequestEvent::class, SubRequestListenerRequestEvent::class);
    }
} else {
    if (! class_exists(SubRequestListenerRequestEvent::class, false)) {
        class_alias(GetResponseEvent::class, SubRequestListenerRequestEvent::class);
    }
}

/**
 * This listener ensures that a new {@see \Sentry\State\Scope} is created for
 * each subrequest.
 */
final class SubRequestListener
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * Constructor.
     *
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * This method is called for each subrequest handled by the framework and
     * pushes a new {@see \Sentry\State\Scope} onto the stack.
     *
     * @param SubRequestListenerRequestEvent $event The event
     */
    public function handleKernelRequestEvent(SubRequestListenerRequestEvent $event): void
    {
        if ($event->isMasterRequest()) {
            return;
        }

        $this->hub->pushScope();
    }

    /**
     * This method is called for each subrequest handled by the framework and
     * pops a {@see \Sentry\State\Scope} from the stack.
     *
     * @param FinishRequestEvent $event The event
     */
    public function handleKernelFinishRequestEvent(FinishRequestEvent $event): void
    {
        if ($event->isMasterRequest()) {
            return;
        }

        $this->hub->popScope();
    }
}
