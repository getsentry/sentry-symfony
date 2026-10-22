<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Config;

use Symfony\Component\Config\Definition\FloatNode;

/**
 * @internal
 */
final class NullableFloatNode extends FloatNode
{
    /**
     * @param mixed $value
     */
    protected function validateType($value): void
    {
        if (null === $value) {
            return;
        }

        parent::validateType($value);
    }
}
