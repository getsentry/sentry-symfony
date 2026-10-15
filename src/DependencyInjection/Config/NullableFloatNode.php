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

        // Symfony types the placeholder of `%env(default::float:FOO)%` as a
        // string, even though its empty fallback resolves to null at runtime.
        if ($this->isHandlingPlaceholder() && \is_string($value)) {
            return;
        }

        parent::validateType($value);
    }

    /**
     * @return string[]
     */
    protected function getValidPlaceholderTypes(): array
    {
        return ['float', 'string'];
    }
}
