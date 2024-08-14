<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;

/**
 * This event listener acts on the sub requests and starts a child span of the
 * current transaction to gather performance data for each of them.
 */
final class TracingSubRequestListener extends AbstractTracingRequestListener
{
    /**
     * This method is called for each subrequest handled by the framework and
     * traces each by starting a new {@see Span}.
     *
     * @param RequestEvent $event The event
     */
    public function handleKernelRequestEvent(RequestEvent $event): void
    {
        if ($this->isMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();
        $span = $this->hub->getSpan();

        if (null === $span) {
            return;
        }

        $this->hub->setSpan(
            $span->startChild(
                SpanContext::make()
                    ->setOp('http.server')
                    ->setData([
                        'http.request.method' => $request->getMethod(),
                        'http.url' => $request->getUri(),
                        'route' => $this->getRouteName($request),
                    ])
                    ->setOrigin('auto.http.server')
                    ->setDescription(\sprintf('%s %s%s%s', $request->getMethod(), $request->getSchemeAndHttpHost(), $request->getBaseUrl(), $request->getPathInfo()))
            )
        );
    }

    /**
     * This method is called for each subrequest handled by the framework and
     * ends the tracing.
     *
     * @param FinishRequestEvent $event The event
     */
    public function handleKernelFinishRequestEvent(FinishRequestEvent $event): void
    {
        if ($this->isMainRequest($event)) {
            return;
        }

        $span = $this->hub->getSpan();

        if (null === $span) {
            return;
        }

        $span->finish();
    }
}
