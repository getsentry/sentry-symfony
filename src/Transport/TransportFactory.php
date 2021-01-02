<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Transport;

use Http\Client\HttpAsyncClient as HttpAsyncClientInterface;
use Http\Discovery\Psr17FactoryDiscovery;
use Jean85\PrettyVersions;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LoggerInterface;
use Sentry\HttpClient\HttpClientFactory;
use Sentry\Options;
use Sentry\Transport\DefaultTransportFactory;
use Sentry\Transport\TransportFactoryInterface;
use Sentry\Transport\TransportInterface;

/**
 * This class wraps the default transport factory provided by the core SDK and
 * discovers automatically the PSR-17 factory if the user did not configure it
 * explicitly.
 */
final class TransportFactory implements TransportFactoryInterface
{
    /**
     * @var DefaultTransportFactory
     */
    private $decoratedTransportFactory;

    public function __construct(
        ?UriFactoryInterface $uriFactory = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?ResponseFactoryInterface $responseFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?HttpAsyncClientInterface $httpClient = null,
        ?LoggerInterface $logger = null
    ) {
        $uriFactory = $uriFactory ?? Psr17FactoryDiscovery::findUriFactory();
        $requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $responseFactory = $responseFactory ?? Psr17FactoryDiscovery::findResponseFactory();
        $streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();

        $this->decoratedTransportFactory = new DefaultTransportFactory(
            $streamFactory,
            $requestFactory,
            new HttpClientFactory(
                $uriFactory,
                $responseFactory,
                $streamFactory,
                $httpClient,
                'sentry.php.symfony',
                PrettyVersions::getRootPackageVersion()->getPrettyVersion()
            ),
            $logger
        );
    }

    public function create(Options $options): TransportInterface
    {
        return $this->decoratedTransportFactory->create($options);
    }
}
