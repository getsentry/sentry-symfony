<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;

final class TracingRequestListener extends AbstractTracingRequestListener
{
    /**
     * @param RequestListenerRequestEvent $event The event
     */
    public function handleKernelRequestEvent(RequestListenerRequestEvent $event): void
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        /** @var Request $request */
        $request = $event->getRequest();
        $requestStartTime = $request->server->get('REQUEST_TIME_FLOAT', microtime(true));

        $context = new TransactionContext();
        $context->setOp('http.server');
        $context->setName(sprintf('%s %s%s%s', $request->getMethod(), $request->getSchemeAndHttpHost(), $request->getBaseUrl(), $request->getPathInfo()));
        $context->setStartTimestamp($requestStartTime);
        $context->setTags($this->getTags($request));

        $this->hub->setSpan($this->hub->startTransaction($context));
    }

    /**
     * This method is called for each request handled by the framework and
     * ends the tracing.
     *
     * @param FinishRequestEvent $event The event
     */
    public function handleKernelFinishRequestEvent(FinishRequestEvent $event): void
    {
        if (!$event->isMasterRequest()) {
            return;
        }

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
        $tags = [
            'net.host.port' => (string) $request->getPort(),
            'http.method' => $request->getMethod(),
            'http.url' => $request->getUri(),
            'http.flavor' => $this->getHttpFlavor($request),
            'route' => $this->getRouteName($request),
        ];

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
    protected function getHttpFlavor(Request $request): string
    {
        $protocolVersion = $request->getProtocolVersion();

        if (str_starts_with($protocolVersion, 'HTTP/')) {
            return substr($protocolVersion, \strlen('HTTP/'));
        }

        return $protocolVersion;
    }
}
