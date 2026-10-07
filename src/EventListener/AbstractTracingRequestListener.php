<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpBodyCollector;
use Sentry\DataCollection\HttpCookieCollector;
use Sentry\DataCollection\HttpHeaderCollector;
use Sentry\DataCollection\HttpMessageType;
use Sentry\DataCollection\HttpUrlCollector;
use Sentry\DataCollection\KeyValueDataFilter;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
     * This method will return the query string as it was received, compared
     * to {@see Request::getUri()} that normalizes it.
     *
     * @internal
     */
    protected function getRequestUrl(Request $request, DataCollectionPolicy $policy): string
    {
        if ($policy->isLegacyMode()) {
            return $request->getUri();
        }

        $url = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo();
        $queryString = $request->server->get('QUERY_STRING');
        $queryString = HttpUrlCollector::collectQueryString($policy, \is_string($queryString) ? $queryString : '');

        if (null !== $queryString) {
            $url .= '?' . $queryString;
        }

        return $url;
    }

    /**
     * Adds the headers, cookies and body of the response to the span.
     *
     * @internal
     */
    protected function collectResponseData(Span $span, Response $response): void
    {
        if (!$span->getSampled()) {
            return;
        }

        $policy = DataCollectionPolicy::fromHub($this->hub);
        $spanData = [];

        // Headers can be set to null, which removes their value
        $responseHeaders = [];
        foreach ($response->headers->all() as $name => $values) {
            $responseHeaders[$name] = array_values(array_filter($values, '\is_string'));
        }

        $headers = HttpHeaderCollector::collect($policy, HttpMessageType::outgoingResponse(), $responseHeaders);
        if (null !== $headers) {
            foreach ($headers as $name => $values) {
                $spanData['http.response.header.' . $name] = implode(', ', $values);
            }
        }

        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[] = [$cookie->getName(), $cookie->getValue()];
        }

        $collectedCookies = HttpCookieCollector::collectGroupedPairs($policy, HttpMessageType::outgoingResponse(), $cookies);
        if (\is_array($collectedCookies)) {
            foreach ($collectedCookies as $name => $value) {
                $spanData['http.response.header.set_cookie.' . $name] = $value;
            }
        }

        $content = $response->getContent();
        if (false !== $content) {
            $body = HttpBodyCollector::collect($policy, HttpMessageType::outgoingResponse(), $content, (string) $response->headers->get('Content-Type', ''));
            if (\is_array($body)) {
                $body = json_encode($body) ?: KeyValueDataFilter::FILTERED_VALUE;
            }

            if (null !== $body) {
                $spanData['http.response.body.data'] = $body;
            }
        } elseif (null !== $policy->getHttpBodyLimit(HttpMessageType::outgoingResponse())) {
            $spanData['http.response.body.data'] = KeyValueDataFilter::FILTERED_VALUE;
        }

        $span->setData($spanData);
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
