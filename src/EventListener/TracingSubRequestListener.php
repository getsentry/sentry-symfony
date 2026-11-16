<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * This event listener acts on the sub requests and starts a child span of the
 * current transaction to gather performance data for each of them.
 */
final class TracingSubRequestListener extends AbstractTracingRequestListener
{
    /**
     * @var \SplObjectStorage<Request, Span> The spans started for the sub-requests that are being handled
     */
    private $spans;

    public function __construct(HubInterface $hub, bool $enabled = true)
    {
        parent::__construct($hub, $enabled);

        $this->spans = new \SplObjectStorage();
    }

    /**
     * This method is called for each subrequest handled by the framework and
     * traces each by starting a new {@see Span}.
     *
     * @param RequestEvent $event The event
     */
    public function handleKernelRequestEvent(RequestEvent $event): void
    {
        if (!$this->enabled || $this->isMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();
        $span = $this->hub->getSpan();

        if (null === $span) {
            return;
        }

        $subRequestSpan = $span->startChild(
            SpanContext::make()
                ->setOp('http.server')
                ->setData([
                    'http.request.method' => $request->getMethod(),
                    'http.url' => $this->getRequestUrl($request, DataCollectionPolicy::fromHub($this->hub)),
                    'route' => $this->getRouteName($request),
                ])
                ->setOrigin('auto.http.server')
                ->setDescription(\sprintf('%s %s%s%s', $request->getMethod(), $request->getSchemeAndHttpHost(), $request->getBaseUrl(), $request->getPathInfo()))
        );

        $this->spans[$request] = $subRequestSpan;
        $this->hub->setSpan($subRequestSpan);
    }

    /**
     * Sets the HTTP status of the response on the span of the sub-request.
     */
    public function handleKernelResponseEvent(ResponseEvent $event): void
    {
        if (!$this->enabled || $this->isMainRequest($event)) {
            return;
        }

        $span = $this->getSubRequestSpan($event->getRequest());

        if (null === $span) {
            return;
        }

        $response = $event->getResponse();

        $span->setHttpStatus($response->getStatusCode());
        $span->setData(['http.response.status_code' => $response->getStatusCode()]);
    }

    /**
     * @param ResponseEvent $event
     */
    public function collectKernelResponseData(ResponseEvent $event): void
    {
        if (!$this->enabled || $this->isMainRequest($event)) {
            return;
        }

        $span = $this->getSubRequestSpan($event->getRequest());

        if (null === $span) {
            return;
        }

        $this->collectResponseData($span, $event->getResponse());
    }

    /**
     * This method is called for each subrequest handled by the framework and
     * ends the tracing.
     *
     * @param FinishRequestEvent $event The event
     */
    public function handleKernelFinishRequestEvent(FinishRequestEvent $event): void
    {
        if (!$this->enabled || $this->isMainRequest($event)) {
            return;
        }

        $request = $event->getRequest();
        $span = $this->getSubRequestSpan($request);

        // A sub-request can fail before its span is started, e.g. if no route matches. The current span is not
        // the span of the sub-request then, finishing it would end the transaction in the middle of the request.
        if (null === $span) {
            return;
        }

        unset($this->spans[$request]);

        $span->finish();
    }

    private function getSubRequestSpan(Request $request): ?Span
    {
        return isset($this->spans[$request]) ? $this->spans[$request] : null;
    }
}
