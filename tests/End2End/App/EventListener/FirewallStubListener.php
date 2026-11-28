<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\EventListener;

use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * Stands in for Symfony's firewall: looks up a token and rejects the request.
 */
class FirewallStubListener
{
    /**
     * @var HubInterface
     */
    private $hub;

    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ('/tracing/firewall-rejected' !== $event->getRequest()->getPathInfo()) {
            return;
        }

        $span = $this->hub->getSpan();

        if (null !== $span) {
            $span->startChild(SpanContext::make()->setOp('firewall.token_lookup'))->finish();
        }

        $event->setResponse(new Response('', 401));
    }
}
