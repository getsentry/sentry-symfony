<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

class MainController
{
    /**
     * @var HubInterface
     */
    private $sentry;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var HttpKernelInterface
     */
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

    public function fatal(): Response
    {
        $foo = eval("return new class() implements \JsonSerializable {};");

        return new Response('This response should not happen: ' . json_encode($foo));
    }

    public function badRequest(): Response
    {
        throw new BadRequestHttpException('Parameter "foo" not allowed');
    }

    public function index(): Response
    {
        $this->sentry->captureMessage('Hello there');

        return new Response('Hello there');
    }

    public function notice(): Response
    {
        @trigger_error('This is an intentional notice', \E_USER_NOTICE);

        return new Response('Hello there');
    }

    public function subrequest(): Response
    {
        $request = $this->requestStack->getCurrentRequest();
        \assert($request instanceof Request);
        $path['_controller'] = __CLASS__ . '::index';

        $subRequest = $request->duplicate([], null, $path);

        return $this->kernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }

    public function runtimeContext(Request $request): JsonResponse
    {
        $requestTag = (string) $request->query->get('request', 'none');
        $leakTag = $request->query->get('leak');

        $this->sentry->configureScope(static function (Scope $scope) use ($requestTag, $leakTag): void {
            $scope->setTag('runtime.request', $requestTag);

            if (\is_string($leakTag) && '' !== $leakTag) {
                $scope->setTag('runtime.leak', $leakTag);
            }
        });

        $this->sentry->captureMessage('Runtime context check');

        return new JsonResponse([
            'runtime_context_id' => SentrySdk::getCurrentRuntimeContext()->getId(),
        ]);
    }
}
