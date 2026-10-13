<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monolog;

use Monolog\Handler\AbstractHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Sentry\Exception\SilencedErrorException;
use Sentry\State\HubInterface;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\ErrorHandler\Exception\SilencedErrorContext;

final class PhpErrorHandler extends AbstractHandler
{
    /**
     * The fatal error types that cannot be silenced using the @ operator in PHP 8+, see {@see \Sentry\ErrorHandler}.
     */
    private const PHP8_UNSILENCEABLE_FATAL_ERRORS = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR | \E_RECOVERABLE_ERROR;

    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var CapturedExceptionTracker
     */
    private $tracker;

    /**
     * @var \ReflectionProperty
     */
    private $traceReflection;

    /**
     * @var bool
     */
    private $bubbleSilencedErrors;

    /**
     * @param bool $bubbleSilencedErrors Whether the records of @-silenced errors are passed to the next handlers. They
     *                                   are only logged by Symfony in "scream" mode: when we enable it although the
     *                                   application doesn't, we stop them here so the other handlers never see them,
     *                                   like without scream mode
     */
    public function __construct(HubInterface $hub, CapturedExceptionTracker $tracker, bool $bubbleSilencedErrors = true)
    {
        parent::__construct(Logger::DEBUG, true);

        $this->hub = $hub;
        $this->tracker = $tracker;
        $this->bubbleSilencedErrors = $bubbleSilencedErrors;
        $this->traceReflection = new \ReflectionProperty(\Exception::class, 'trace');

        if (\PHP_VERSION_ID < 80100) {
            $this->traceReflection->setAccessible(true);
        }
    }

    /**
     * @param array<string, mixed>|LogRecord $record
     */
    public function handle($record): bool
    {
        if ($record instanceof LogRecord) {
            $error = $record->context['exception'] ?? null;
        } else {
            $error = $record['context']['exception'] ?? null;
        }

        // We only care about errors and can skip all other logs
        if (!$error instanceof \Throwable && !$error instanceof SilencedErrorContext) {
            return false;
        }

        // Symfony only attaches a SilencedErrorContext to the records of @-silenced errors it logs in scream mode.
        // Silenced deprecations are always logged, with an \ErrorException, so they always bubble.
        $stopPropagation = $error instanceof SilencedErrorContext && !$this->bubbleSilencedErrors;

        if ($this->tracker->isLastCaptured($error)) {
            return $stopPropagation;
        }

        $exception = $this->createException($error, $record['message']);

        if (null !== $exception) {
            $this->tracker->setLastCaptured($error);
            $this->hub->captureException($exception);
        }

        return $stopPropagation;
    }

    /**
     * @param \Throwable|SilencedErrorContext $error
     */
    private function createException($error, string $message): ?\Throwable
    {
        $client = $this->hub->getClient();

        if (null === $client) {
            return null;
        }

        $options = $client->getOptions();

        // Like the SDK's own error handler, errors silenced with the @ operator are only filtered by the
        // `capture_silenced_errors` option, not by `error_types`: while they are handled, error_reporting() returns
        // the level set by the @ operator, which `error_types` defaults to
        if ($error instanceof SilencedErrorContext) {
            if (!$options->shouldCaptureSilencedErrors()) {
                return null;
            }

            $exception = new SilencedErrorException($message, 0, $error->getSeverity(), $error->getFile(), $error->getLine());
            $this->traceReflection->setValue($exception, $error->getTrace());

            return $exception;
        }

        if ($error instanceof \ErrorException) {
            // Symfony never reports deprecations as silenced. This handler runs synchronously from Symfony's
            // ErrorHandler, so the error level set by the @ operator is still active and tells us.
            if ($this->isSilenced($error->getSeverity())) {
                if (!$options->shouldCaptureSilencedErrors()) {
                    return null;
                }

                $exception = new SilencedErrorException($error->getMessage(), 0, $error->getSeverity(), $error->getFile(), $error->getLine());
                $this->traceReflection->setValue($exception, $error->getTrace());

                return $exception;
            }

            return 0 !== ($options->getErrorTypes() & $error->getSeverity()) ? $error : null;
        }

        if ($error instanceof FatalError) {
            return 0 !== ($options->getErrorTypes() & $error->getError()['type']) ? $error : null;
        }

        // Uncaught exceptions handled by Symfony's ErrorHandler outside of the kernel
        return $error;
    }

    /**
     * Same detection as the SDK's own error handler, see {@see \Sentry\ErrorHandler}.
     */
    private function isSilenced(int $severity): bool
    {
        if (\PHP_VERSION_ID < 80000) {
            return 0 === error_reporting();
        }

        // Since PHP 8, error_reporting() returns the unsilenceable fatal errors while the @ operator is active, and
        // errors of those levels are never silenced
        return 0 === (error_reporting() & ~self::PHP8_UNSILENCEABLE_FATAL_ERRORS)
            && $severity !== (self::PHP8_UNSILENCEABLE_FATAL_ERRORS & $severity);
    }
}
