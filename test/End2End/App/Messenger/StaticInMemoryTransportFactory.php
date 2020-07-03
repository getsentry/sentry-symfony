<?php

namespace Sentry\SentryBundle\Test\End2End\App\Messenger;

use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class StaticInMemoryTransportFactory implements TransportFactoryInterface
{
    /**
     * @param mixed[] $options
     */
    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        return new StaticInMemoryTransport();
    }

    /**
     * @param mixed[] $options
     */
    public function supports(string $dsn, array $options): bool
    {
        return 'static://' === $dsn;
    }
}
