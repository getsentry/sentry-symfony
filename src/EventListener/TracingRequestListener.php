<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;

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
     * @param RequestListenerRequestEvent $event The event
     */
    public function handleKernelRequestEvent(RequestListenerRequestEvent $event): void
    {
        if (!$this->isMainRequest($event)) {
            return;
        }

        /** @var Request $request */
        $request = $event->getRequest();
        $requestStartTime = $request->server->get('REQUEST_TIME_FLOAT', microtime(true));

        $context = TransactionContext::fromSentryTrace($request->headers->get('sentry-trace', ''));
        $context->setOp('http.server');
        $context->setName(sprintf('%s %s%s%s', $request->getMethod(), $request->getSchemeAndHttpHost(), $request->getBaseUrl(), $request->getPathInfo()));
        $context->setStartTimestamp($requestStartTime);
        $context->setTags($this->getTags($request));

        $this->hub->setSpan($this->hub->startTransaction($context));
    }

    /**
     * This method is called for each request handled by the framework and
     * ends the tracing on terminate after the client received the response.
     *
     * @param RequestListenerTerminateEvent $event The event
     */
    public function handleKernelTerminateEvent(RequestListenerTerminateEvent $event): void
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
