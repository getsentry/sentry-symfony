<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monolog;

/**
 * Shared by the Monolog handlers reporting errors: some versions of Symfony log the same error twice, to different
 * channels and thus to different handlers, so we store the previously captured error here for deduplication.
 *
 * @internal
 */
final class CapturedExceptionTracker
{
    /**
     * @var object|null
     */
    private $lastCaptured;

    public function isLastCaptured(object $error): bool
    {
        return $error === $this->lastCaptured;
    }

    public function setLastCaptured(object $error): void
    {
        $this->lastCaptured = $error;
    }
}
