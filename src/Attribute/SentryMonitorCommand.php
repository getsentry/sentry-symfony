<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
class SentryMonitorCommand
{
    /**
     * @var string
     */
    private $slug;

    public function __construct(string $slug)
    {
        $this->slug = $slug;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }
}
