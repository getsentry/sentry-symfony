<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener\Fixtures;

final class StringableReportMessage
{
    /**
     * @var string
     */
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
