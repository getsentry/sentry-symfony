<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * This listener ensures that a new {@see \Sentry\State\Scope} is created for
 * each subrequest.
 */
final class SubRequestListener
{
    use KernelEventForwardCompatibilityTrait;

    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var \SplObjectStorage<Request, true> The sub-requests a scope was pushed for
     */
    private $requests;

    /**
     * Constructor.
     *
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
        $this->requests = new \SplObjectStorage();
    }

    /**
     * This method is called for each subrequest handled by the framework and
     * pushes a new {@see \Sentry\State\Scope} onto the stack.
     *
     * @param RequestEvent $event The event
     */
    public function handleKernelRequestEvent(RequestEvent $event): void
    {
        if ($this->isMainRequest($event)) {
            return;
        }

        $this->hub->pushScope();
        $this->requests[$event->getRequest()] = true;
    }

    /**
     * This method is called for each subrequest handled by the framework and
     * pops a {@see \Sentry\State\Scope} from the stack.
     *
     * @param FinishRequestEvent $event The event
     */
    public function handleKernelFinishRequestEvent(FinishRequestEvent $event): void
    {
        if ($this->isMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();

        // A sub-request can fail before its scope is pushed, e.g. if no route matches
        if (!isset($this->requests[$request])) {
            return;
        }

        unset($this->requests[$request]);

        $this->hub->popScope();
    }
}
