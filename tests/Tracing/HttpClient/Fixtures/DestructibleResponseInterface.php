<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\HttpClient\Fixtures;

use Symfony\Contracts\HttpClient\ResponseInterface;

interface DestructibleResponseInterface extends ResponseInterface
{
    public function __destruct();
}
