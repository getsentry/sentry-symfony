<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Integration;

use Http\Discovery\Psr17FactoryDiscovery;
use Psr\Http\Message\ServerRequestInterface;
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
            Psr17FactoryDiscovery::findServerRequestFactory(),
            Psr17FactoryDiscovery::findStreamFactory(),
            Psr17FactoryDiscovery::findUploadedFileFactory(),
            Psr17FactoryDiscovery::findResponseFactory()
        );
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

        try {
            return $this->httpMessageFactory->createRequest($request);
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
