<?php

namespace Sentry\SentryBundle\Test\End2End\App\Controller;

use Sentry\State\HubInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class MainController
{
    /** @var HubInterface */
    private $sentry;

    /** @var RequestStack */
    private $requestStack;

    /** @var HttpKernelInterface */
    private $kernel;

    public function __construct(HubInterface $sentry, RequestStack $requestStack, HttpKernelInterface $kernel)
    {
        $this->sentry = $sentry;
        $this->requestStack = $requestStack;
        $this->kernel = $kernel;
    }

    public function exception(): Response
    {
        throw new \RuntimeException('This is an intentional error');
    }

    public function index(): Response
    {
        $this->sentry->captureMessage('Hello there');

        return new Response('Hello there');
    }

    public function subrequest(): Response
    {
        $request = $this->requestStack->getCurrentRequest();
        assert($request instanceof Request);
        $path['_controller'] = __CLASS__ . '::index';

        $subRequest = $request->duplicate([], null, $path);

        return $this->kernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }
}
