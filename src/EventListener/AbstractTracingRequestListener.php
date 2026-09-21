<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\DataCollection\DataCollectionOptions;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\HttpBodyCollector;
use Sentry\DataCollection\HttpDataCollector;
use Sentry\DataCollection\HttpHeaderNormalizer;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

        $policy = DataCollectionPolicy::fromHub($this->hub);
        if ($policy->isLegacyMode()) {
            return;
        }

        $response = $event->getResponse();
        $headers = HttpHeaderNormalizer::normalize($response->headers->getIterator()->getArrayCopy());
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[] = [$cookie->getName(), $cookie->getValue()];
        }

        $data = HttpDataCollector::collectResponseData($policy, $headers, $cookies);
        $data += $this->collectResponseBodyData($span, $response, $policy, $headers);

        $span->setData(array_diff_key($data, $span->getData()));
    }

    /**
     * @param array<array-key, string[]> $headers
     *
     * @return array<string, mixed>
     */
    private function collectResponseBodyData(Span $span, Response $response, DataCollectionPolicy $policy, array $headers): array
    {
        if (\array_key_exists('http.response.body.data', $span->getData())) {
            return [];
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return [];
        }

        if (0 === HttpBodyCollector::getMaxBodyLength($policy, DataCollectionOptions::HTTP_BODY_OUTGOING_RESPONSE)) {
            return [];
        }

        $body = $response->getContent();
        if (false === $body) {
            return [];
        }

        return HttpDataCollector::collectBodyData($policy, DataCollectionOptions::HTTP_BODY_OUTGOING_RESPONSE, $body, $headers['content-type'][0] ?? '');
    }

    protected function collectRequestData(Span $span, Request $request, DataCollectionPolicy $policy): void
    {
        if (!$span->getSampled()) {
            return;
        }

        if ($policy->isLegacyMode()) {
            return;
        }

        $headers = HttpHeaderNormalizer::normalize($request->headers->getIterator()->getArrayCopy());
        $data = HttpDataCollector::collectRequestData($policy, $headers, $request->cookies->all());
        $data += $this->collectRequestBodyData($span, $request, $policy, $headers);

        $span->setData(array_diff_key($data, $span->getData()));
    }

    /**
     * @param array<array-key, string[]> $headers
     *
     * @return array<string, mixed>
     */
    private function collectRequestBodyData(Span $span, Request $request, DataCollectionPolicy $policy, array $headers): array
    {
        if (\array_key_exists('http.request.body.data', $span->getData())) {
            return [];
        }

        $limit = HttpBodyCollector::getMaxBodyLength($policy, DataCollectionOptions::HTTP_BODY_INCOMING_REQUEST);
        if (0 === $limit) {
            return [];
        }

        if ((float) $request->headers->get('Content-Length', '0') > $limit) {
            return [];
        }

        $body = $request->request->all();
        if ([] === $body) {
            $body = $request->getContent();
        }

        return HttpDataCollector::collectBodyData($policy, DataCollectionOptions::HTTP_BODY_INCOMING_REQUEST, $body, $headers['content-type'][0] ?? '');
    }

    protected function getRequestUrl(Request $request, DataCollectionPolicy $policy): string
    {
        // getUri() normalizes the query, losing repeated parameters and original encoding.
        $url = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo();
        /** @var string $query */
        $query = $request->server->get('QUERY_STRING', '');
        if ('' !== $query) {
            $url .= '?' . $query;
        }

        return HttpDataCollector::collectUrl($policy, $url, $request->getUri());
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
