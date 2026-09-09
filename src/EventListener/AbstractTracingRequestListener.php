<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\DataCollection\DataCollectionOptions;
use Sentry\DataCollection\HttpDataCollector;
use Sentry\DataCollection\HttpHeaderNormalizer;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\Routing\Route;

abstract class AbstractTracingRequestListener
{
    use KernelEventForwardCompatibilityTrait;

    /**
     * @var HubInterface The current hub
     */
    protected $hub;

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
     * This method is called once a response for the current HTTP request is
     * created, but before it is sent off to the client. Its use is mainly for
     * gathering information like the HTTP status code and attaching them as
     * tags of the span/transaction.
     *
     * @param ResponseEvent $event The event
     */
    public function handleKernelResponseEvent(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $span = $this->hub->getSpan();

        if (null === $span) {
            return;
        }

        $span->setHttpStatus($response->getStatusCode());
    }

    /**
     * Collects prepared response data for both main requests and subrequests.
     *
     * @internal
     */
    public function collectKernelResponseEvent(ResponseEvent $event): void
    {
        $span = $this->hub->getSpan();

        if (null === $span || !$span->getSampled()) {
            return;
        }

        $dataCollection = DataCollectionOptions::fromHub($this->hub);
        $response = $event->getResponse();
        $headers = HttpHeaderNormalizer::normalize($response->headers->getIterator()->getArrayCopy());
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[] = [$cookie->getName(), $cookie->getValue()];
        }
        HttpDataCollector::setMissingSpanData($span, HttpDataCollector::collectResponseData($dataCollection, $headers, $cookies));
    }

    protected function collectRequestData(Span $span, Request $request, ?DataCollectionOptions $dataCollection): void
    {
        if (!$span->getSampled()) {
            return;
        }

        $headers = HttpHeaderNormalizer::normalize($request->headers->getIterator()->getArrayCopy());
        HttpDataCollector::setMissingSpanData($span, HttpDataCollector::collectRequestData($dataCollection, $headers, $request->cookies->all()));
    }

    protected function getRequestUrl(Request $request, ?DataCollectionOptions $dataCollection): string
    {
        // getUri() normalizes the query, losing repeated parameters and original encoding.
        $url = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo();
        /** @var string $query */
        $query = $request->server->get('QUERY_STRING', '');
        if ('' !== $query) {
            $url .= '?' . $query;
        }

        return HttpDataCollector::collectUrl($dataCollection, $url, $request->getUri());
    }

    /**
     * Gets the name of the route or fallback to the controller FQCN if the
     * route is anonymous (e.g. a subrequest).
     *
     * @param Request $request The HTTP request
     */
    protected function getRouteName(Request $request): string
    {
        $route = $request->attributes->get('_route');

        if ($route instanceof Route) {
            $route = $route->getPath();
        }

        if (null === $route) {
            $route = $request->attributes->get('_controller');

            if (\is_array($route) && \is_callable($route, true)) {
                $route = \sprintf('%s::%s', \is_object($route[0]) ? get_debug_type($route[0]) : $route[0], $route[1]);
            }
        }

        return \is_string($route) ? $route : '<unknown>';
    }
}
