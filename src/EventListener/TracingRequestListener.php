<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Tracing\TransactionSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

/**
 * This event listener acts on the master requests and starts a transaction
 * to report performance data to Sentry. It gathers useful data like the
 * HTTP status code of the response or the name of the route that handles
 * the request and add them as tags.
 */
final class TracingRequestListener extends AbstractTracingRequestListener
{
    /**
     * This method is called for each subrequest handled by the framework and
     * starts a new {@see Transaction}.
     *
     * @param RequestEvent $event The event
     */
    public function handleKernelRequestEvent(RequestEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        /** @var Request $request */
        $request = $event->getRequest();

        /** @var float $requestStartTime */
        $requestStartTime = $request->server->get('REQUEST_TIME_FLOAT', microtime(true));

        $context = TransactionContext::fromHeaders(
            $request->headers->get('sentry-trace', ''),
            $request->headers->get('baggage', '')
        );
        $context->setOp('http.server');

        $routeName = $request->attributes->get('_route');
        if (null !== $routeName && \is_string($routeName)) {
            $context->setName(sprintf('%s %s', $request->getMethod(), $routeName));
            $context->setSource(TransactionSource::route());
        } else {
            $context->setName(sprintf('%s %s%s%s', $request->getMethod(), $request->getSchemeAndHttpHost(), $request->getBaseUrl(), $request->getPathInfo()));
            $context->setSource(TransactionSource::url());
        }

        $context->setStartTimestamp($requestStartTime);
        $context->setTags($this->getTags($request));

        $this->hub->setSpan($this->hub->startTransaction($context));
    }

    /**
     * This method is called for each request handled by the framework and
     * ends the tracing on terminate after the client received the response.
     *
     * @param TerminateEvent $event The event
     */
    public function handleKernelTerminateEvent(TerminateEvent $event): void
    {
        $transaction = $this->hub->getTransaction();

        if (null === $transaction) {
            return;
        }

        $transaction->finish();
    }

    /**
     * Gets the tags to attach to the transaction.
     *
     * @param Request $request The HTTP request
     *
     * @return array<string, string>
     */
    private function getTags(Request $request): array
    {
        $client = $this->hub->getClient();
        $httpFlavor = $this->getHttpFlavor($request);
        $tags = [
            'net.host.port' => (string) $request->getPort(),
            'http.method' => $request->getMethod(),
            'http.url' => $request->getUri(),
            'route' => $this->getRouteName($request),
        ];

        if (null !== $httpFlavor) {
            $tags['http.flavor'] = $httpFlavor;
        }

        if (false !== filter_var($request->getHost(), \FILTER_VALIDATE_IP)) {
            $tags['net.host.ip'] = $request->getHost();
        } else {
            $tags['net.host.name'] = $request->getHost();
        }

        if (null !== $request->getClientIp() && null !== $client && $client->getOptions()->shouldSendDefaultPii()) {
            $tags['net.peer.ip'] = $request->getClientIp();
        }

        return $tags;
    }

    /**
     * Gets the HTTP flavor from the request.
     *
     * @param Request $request The HTTP request
     */
    private function getHttpFlavor(Request $request): ?string
    {
        $protocolVersion = $request->getProtocolVersion();

        if (null !== $protocolVersion && str_starts_with($protocolVersion, 'HTTP/')) {
            return substr($protocolVersion, \strlen('HTTP/'));
        }

        return $protocolVersion;
    }
}
