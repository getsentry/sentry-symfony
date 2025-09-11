<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\CacheInterface;

class TracingCacheController
{
    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var Connection|null
     */
    private $connection;

    public function __construct(CacheInterface $cache, ?Connection $connection = null)
    {
        $this->cache = $cache;
        $this->connection = $connection;
    }

    public function populateCacheWithString()
    {
        $this->cache->get('example', function () {
            return 'example-string';
        });

        return new Response();
    }

    public function populateCacheWithInteger()
    {
        $this->cache->get('numeric', function () {
            return 1234;
        });

        return new Response();
    }

    public function deleteCache()
    {
        $this->cache->delete('example');

        return new Response();
    }

    public function getWithDbTrace()
    {
        $this->cache->get('fetched-value', function () {
            $this->connection->executeQuery('SELECT 1');

            return 'value';
        });

        return new Response();
    }

    public function crashInCallback()
    {
        $this->cache->get('crash', function () {
            throw new \RuntimeException('crash in callback');
        });

        return new Response();
    }
}
