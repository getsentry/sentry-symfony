<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Sentry\SentryBundle\Tracing\HttpClient\TraceableHttpClient;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

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

    public function httpClientCollection(Request $request): Response
    {
        $httpClient = new TraceableHttpClient(new MockHttpClient(new MockResponse('{"name":"Bob","token":"secret"}', [
            'http_code' => $request->query->getBoolean('error') ? 500 : 200,
            'response_headers' => ['Content-Type: application/json', 'X-Test: response', 'Set-Cookie: theme=light; Path=/', 'Set-Cookie: session_id=secret; HttpOnly'],
        ])), $this->sentry);
        $options = [
            'headers' => ['X-Test' => 'request', 'Cookie' => 'theme=dark; session_id=secret'],
        ];
        if ($request->query->getBoolean('defaults')) {
            $httpClient = $httpClient->withOptions($options);
            $options = [];
        }
        $options['query'] = ['search' => 'new', 'token' => 'secret'];
        if ($request->query->getBoolean('body')) {
            $options['json'] = ['name' => 'Alice'];
        }
        $response = $httpClient->request('POST', 'https://example.com?search=old', $options);
        if ($request->query->getBoolean('error')) {
            try {
                $response->getContent();
            } catch (HttpExceptionInterface $exception) {
                return new Response($response->getContent(false));
            }
        }
        if ($request->query->getBoolean('array')) {
            return new JsonResponse($response->toArray());
        }
        if ($request->query->getBoolean('stream')) {
            $content = '';
            foreach ($httpClient->stream($response) as $chunk) {
                if (!$chunk->isTimeout()) {
                    $content .= $chunk->getContent();
                }
            }
        } else {
            $content = $response->getContent();
        }

        return new Response($content);
    }

    public function preparedResponse(Request $request): Response
    {
        $request->setRequestFormat('json');
        if ($request->query->getBoolean('session')) {
            $request->getSession()->set('theme', 'dark');
        }

        return new Response('{"name":"Alice","password":"secret"}');
    }

    public function headerCollection(Request $request): Response
    {
        $isSubrequest = $request->attributes->get('header_subrequest', false);
        if (!$isSubrequest) {
            $subrequest = $request->duplicate(null, null, ['_controller' => __METHOD__, 'header_subrequest' => true]);
            $subrequest->headers->set('X-Test', 'subrequest');
            $subrequest->cookies->set('theme', 'subrequest');
            $this->kernel->handle($subrequest, HttpKernelInterface::SUB_REQUEST);
            $this->sentry->captureMessage('Header collection');
        }

        $span = $this->sentry->getSpan();
        \assert(null !== $span);
        $span->setData(['http.response.header.x-explicit' => null]);
        $span->setData(['http.response.header.set_cookie.explicit' => null]);
        $response = new Response('Headers unchanged', 200, [
            'X-Test' => [$isSubrequest ? 'subrequest' : 'main', 'second'],
            'X-Debug' => 'visible',
            'X-Debug-Extra' => 'visible',
            'X-Test-Extra' => 'visible',
            'Authorization' => 'response-secret',
            'X-Explicit' => 'automatic',
        ]);
        $response->headers->setCookie(new Cookie('session_id', 'response-cookie'));
        $response->headers->setCookie(new Cookie('theme', $isSubrequest ? 'subrequest' : 'light'));
        $response->headers->setCookie(new Cookie('explicit', 'automatic'));

        return $response;
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
