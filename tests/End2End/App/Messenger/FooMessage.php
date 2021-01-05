<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Messenger;

class FooMessage
{
    /**
     * @var bool
     */
    private $shouldRetry;

    public function __construct(bool $shouldRetry = true)
    {
        $this->shouldRetry = $shouldRetry;
    }

    public function shouldRetry(): bool
    {
        return $this->shouldRetry;
    }
}
