<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Sentry\SentryBundle\Sentry;
use Sentry\Tracing\SpanContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class SubRequestController
{
    /**
     * @var Sentry
     */
    private $sentry;

    /**
     * @var HttpKernelInterface
     */
    private $httpKernel;

    public function __construct(Sentry $sentry, HttpKernelInterface $httpKernel)
    {
        $this->sentry = $sentry;
        $this->httpKernel = $httpKernel;
    }

    public function subRequestWithoutRoute(): Response
    {
        try {
            $this->httpKernel->handle(Request::create('/no-such-route'), HttpKernelInterface::SUB_REQUEST, false);
        } catch (NotFoundHttpException $exception) {
            // The sub-request fails in the router, before its span is started
        }

        $span = $this->sentry->getSpan();

        if (null !== $span) {
            $span->startChild(SpanContext::make()->setOp('after.sub_request'))->finish();
        }

        return new Response('Success');
    }
}
