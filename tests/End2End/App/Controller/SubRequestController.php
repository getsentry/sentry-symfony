<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class SubRequestController
{
    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var HttpKernelInterface
     */
    private $httpKernel;

    public function __construct(HubInterface $hub, HttpKernelInterface $httpKernel)
    {
        $this->hub = $hub;
        $this->httpKernel = $httpKernel;
    }

    public function subRequestWithoutRoute(): Response
    {
        try {
            $this->httpKernel->handle(Request::create('/no-such-route'), HttpKernelInterface::SUB_REQUEST, false);
        } catch (NotFoundHttpException $exception) {
            // The sub-request fails in the router, before its span is started
        }

        $span = $this->hub->getSpan();

        if (null !== $span) {
            $span->startChild(SpanContext::make()->setOp('after.sub_request'))->finish();
        }

        return new Response('Success');
    }
}
