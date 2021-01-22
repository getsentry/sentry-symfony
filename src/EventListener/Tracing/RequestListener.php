<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener\Tracing;

use Sentry\SentryBundle\EventListener\RequestListenerControllerEvent;
use Sentry\SentryBundle\EventListener\RequestListenerRequestEvent;
use Sentry\SentryBundle\EventListener\RequestListenerResponseEvent;
use Sentry\SentryBundle\EventListener\RequestListenerTerminateEvent;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\HttpFoundation\Request;

final class RequestListener
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var Transaction|null
     */
    private $transaction = null;

    /**
     * @var Span|null
     */
    private $requestSpan = null;

    /**
     * @var Span|null
     */
    private $controllerSpan = null;

    /**
     * @var Span|null
     */
    private $responseSpan = null;

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
        $context->setName($request->getUri());
        $context->setData([
            'url' => $request->getUri(),
            'method' => strtoupper($request->getMethod()),
        ]);
        $context->setStartTimestamp($requestStartTime);

        $transaction = $this->hub->startTransaction($context);

        // Setting the Transaction on the Hub
        $this->hub->setSpan($transaction);

        $spanContext = new SpanContext();
        $spanContext->setOp('kernel.request');
        $spanContext->setStartTimestamp($requestStartTime);

        $this->requestSpan = $transaction->startChild($spanContext);
        $this->transaction = $transaction;
    }

    /**
     * @param RequestListenerControllerEvent $event The event
     */
    public function handleKernelControllerEvent(RequestListenerControllerEvent $event): void
    {
        if (!$event->isMasterRequest() || !$this->transaction instanceof Transaction) {
            return;
        }

        if ($this->requestSpan instanceof Span) {
            $this->requestSpan->finish();
        }

        $spanContext = new SpanContext();
        $spanContext->setOp('controller');

        $this->controllerSpan = $this->transaction->startChild($spanContext);
    }

    /**
     * @param RequestListenerResponseEvent $event The event
     */
    public function handleKernelResponseEvent(RequestListenerResponseEvent $event): void
    {
        if (!$event->isMasterRequest() || !$this->transaction instanceof Transaction) {
            return;
        }

        $this->transaction->setHttpStatus($event->getResponse()->getStatusCode());

        if ($this->controllerSpan instanceof Span) {
            $this->controllerSpan->finish();
        }

        $spanContext = new SpanContext();
        $spanContext->setOp('kernel.response');

        $this->responseSpan = $this->transaction->startChild($spanContext);
    }

    /**
     * @param RequestListenerTerminateEvent $event The event
     */
    public function handleKernelTerminateEvent(RequestListenerTerminateEvent $event): void
    {
        if (!$this->transaction instanceof Transaction) {
            return;
        }

        if ($this->responseSpan instanceof Span) {
            $this->responseSpan->finish();
        }

        $this->transaction->finish();
    }
}
