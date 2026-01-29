<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

class NamespacedCacheController
{
    /**
     * @var TagAwareCacheInterface
     */
    private $cache;

    public function __construct(TagAwareCacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function populateNamespacedCache(): Response
    {
        $namespaced = $this->cache->withSubNamespace('tests');

        $namespaced->get('namespaced-key', static function (ItemInterface $item) {
            $item->tag(['a tag name']);

            return 'namespaced-value';
        });

        return new Response();
    }
}
