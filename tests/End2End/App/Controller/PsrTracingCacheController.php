<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\HttpFoundation\Response;

class PsrTracingCacheController
{
    /**
     * @var AdapterInterface
     */
    private $adapter;

    public function __construct(CacheItemPoolInterface $adapter)
    {
        $this->adapter = $adapter;
    }

    public function testPopulateString()
    {
        $item = $this->adapter->getItem('foo');
        if (!$item->isHit()) {
            $item->set('example');
            $this->adapter->save($item);
        }

        return new Response();
    }

    public function testDelete()
    {
        $this->adapter->deleteItem('foo');

        return new Response();
    }
}
