<?php

declare(strict_types=1);

namespace Sentry\SentryBundle;

/**
 * This class provides a useful method to parse a string compatible with the
 * `error_reporting` PHP INI setting into the corresponding value represented
 * as an integer.
 *
 * @internal
 */
final class ErrorTypesParser
{
    /**
     * Parses a string that contains either an integer representing a bit field
     * or named constants and returns the corresponding integer value of the
     * bitmask.
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $value): int
    {
        $parsedValue = self::convertErrorConstants($value);

        if ('' === trim($parsedValue)) {
            throw new \InvalidArgumentException('The $value argument cannot be empty.');
        }

        // Sanitize the string from any character which could lead to a security
        // issue. The only accepted chars are digits, spaces, parentheses and the
        // bitwise operators useful to work with a bitmask
        if (0 !== preg_match('/[^\d^|&~() -]/', $parsedValue)) {
            throw new \InvalidArgumentException('The $value argument contains unexpected characters.');
        }

        try {
            return 0 + (int) eval('return ' . $parsedValue . ';');
        } catch (\ParseError $exception) {
            throw new \InvalidArgumentException('The $value argument cannot be parsed to a bitmask.');
        }
    }

    /**
     * Parses the given value and converts all the constant names with their
     * corresponding value.
     *
     * @param string $value e.g. E_ALL & ~E_DEPRECATED & ~E_NOTICE
     *
     * @return string The converted expression e.g. 32767 & ~8192 & ~8
     */
    private static function convertErrorConstants(string $value): string
    {
        $output = preg_replace_callback('/(E_[A-Z_]+)/', static function (array $matches) {
            if (\defined($matches[1])) {
                $constant = \constant($matches[1]);

                if (\is_string($constant)) {
                    return $constant;
                } elseif (\is_int($constant)) {
                    return (string) $constant;
                } elseif (\is_array($constant)) {
                    return implode(' | ', array_map(static function ($value) {
                        return \is_string($value) ? $value : (string) $value;
                    }, $constant));
                } elseif (\is_object($constant)) {
                    return \get_class($constant);
                } else { // Non-scalar values
                    return '';
                }
            }

            return $matches[0];
        }, $value);

        if (null === $output) {
            throw new \InvalidArgumentException(\sprintf('The "%s" value could not be parsed.', $value));
        }

        return $output;
    }
}
