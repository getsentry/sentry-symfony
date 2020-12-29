<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Integration;

use Psr\Http\Message\ServerRequestInterface;
use Sentry\Integration\RequestFetcherInterface;
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
     * @var HttpMessageFactoryInterface The factory to convert Symfony requests to PSR-7 requests
     */
    private $httpMessageFactory;

    /**
     * Class constructor.
     *
     * @param RequestStack                $requestStack       The request stack
     * @param HttpMessageFactoryInterface $httpMessageFactory The factory to convert Symfony requests to PSR-7 requests
     */
    public function __construct(RequestStack $requestStack, HttpMessageFactoryInterface $httpMessageFactory)
    {
        $this->requestStack = $requestStack;
        $this->httpMessageFactory = $httpMessageFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function fetchRequest(): ?ServerRequestInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return null;
        }

        return $this->httpMessageFactory->createRequest($request);
    }
}
