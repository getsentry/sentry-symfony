<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Integration;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Request;
use Sentry\Integration\RequestFetcherInterface;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * This class fetches the server request from the request stack and converts it
 * into a PSR-7 request that is suitable to be used by the {@see \Sentry\Integration\RequestIntegration}
 * integration.
 */
final class RequestFetcher implements RequestFetcherInterface
{
    /**
     * @var RequestStack The request stack
     */
    private $requestStack;

    /**
     * @var \Symfony\Component\HttpFoundation\Request|null The current request
     */
    private $currentRequest;

    /**
     * @var HttpMessageFactoryInterface The factory to convert Symfony requests to PSR-7 requests
     */
    private $httpMessageFactory;

    /**
     * Class constructor.
     *
     * @param RequestStack                     $requestStack       The request stack
     * @param HttpMessageFactoryInterface|null $httpMessageFactory The factory to convert Symfony requests to PSR-7 requests
     */
    public function __construct(RequestStack $requestStack, ?HttpMessageFactoryInterface $httpMessageFactory = null)
    {
        $this->requestStack = $requestStack;
        $this->httpMessageFactory = $httpMessageFactory ?? new PsrHttpFactory(
            new HttpFactory(),
            new HttpFactory(),
            new HttpFactory(),
            new HttpFactory()
        );
    }

    /**
     * {@inheritdoc}
     */
    public function fetchRequest(): ?ServerRequestInterface
    {
        $request = $this->currentRequest ?? $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        try {
            return $this->httpMessageFactory->createRequest($request);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    public function setRequest(?Request $request): void
    {
        $this->currentRequest = $request;
    }
}
