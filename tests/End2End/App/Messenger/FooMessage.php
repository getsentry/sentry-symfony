<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Messenger;

class FooMessage
{
    /**
     * @var bool
     */
    private $shouldRetry;

    /**
     * @var array<string,string>|null
     */
    private $scopeData = null;

    /**
     * @param array<string,string>|null $scopeData
     */
    public function __construct(bool $shouldRetry = true, array $scopeData = null)
    {
        $this->shouldRetry = $shouldRetry;
        $this->scopeData = $scopeData;
    }

    public function shouldRetry(): bool
    {
        return $this->shouldRetry;
    }

    /**
     * @return array<string,string>|null
     */
    public function getScopeData(): ?array
    {
        return $this->scopeData;
    }
}
