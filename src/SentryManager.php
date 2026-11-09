<?php

declare(strict_types=1);

namespace Sentry\SentryBundle;

use Sentry\ClientInterface;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\EventId;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\State\IsolationScope;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

use function Sentry\captureEvent;
use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\configureScope;
use function Sentry\startTransaction;
use function Sentry\withIsolationScope;

/**
 * Replaces the hub that the services of the bundle used before version 5.0 of the SDK.
 *
 * Every method forwards to the SDK state in {@see SentrySdk}, so the services of the
 * bundle and the global `\Sentry\*` functions all work on the same scopes.
 *
 * @internal
 */
class SentryManager
{
    public function getClient(): ClientInterface
    {
        return SentrySdk::getClient();
    }

    public function bindClient(ClientInterface $client): void
    {
        SentrySdk::getGlobalScope()->setClient($client);
    }

    /**
     * Forks the current isolation scope until {@see popScope()} is called.
     */
    public function pushScope(): IsolationScope
    {
        return SentrySdk::pushIsolationScope();
    }

    /**
     * Restores the isolation scope that was active before the last {@see pushScope()} call.
     *
     * @return bool Whether there was a pushed scope to pop
     */
    public function popScope(): bool
    {
        return SentrySdk::popIsolationScope();
    }

    /**
     * Runs the callback with a fork of the current isolation scope.
     *
     * @phpstan-template T
     *
     * @phpstan-param callable(IsolationScope): T $callback
     *
     * @return mixed The callback's return value
     *
     * @phpstan-return T
     */
    public function withScope(callable $callback)
    {
        return withIsolationScope($callback);
    }

    /**
     * @param callable(IsolationScope): void $callback
     */
    public function configureScope(callable $callback): void
    {
        configureScope($callback);
    }

    public function captureMessage(string $message, ?Severity $level = null, ?EventHint $hint = null): ?EventId
    {
        return captureMessage($message, $level, $hint);
    }

    public function captureException(\Throwable $exception, ?EventHint $hint = null): ?EventId
    {
        return captureException($exception, $hint);
    }

    public function captureEvent(Event $event, ?EventHint $hint = null): ?EventId
    {
        return captureEvent($event, $hint);
    }

    /**
     * @param array<string, mixed> $customSamplingContext
     */
    public function startTransaction(TransactionContext $context, array $customSamplingContext = []): Transaction
    {
        return startTransaction($context, $customSamplingContext);
    }

    public function getTransaction(): ?Transaction
    {
        return SentrySdk::getIsolationScope()->getTransaction();
    }

    public function getSpan(): ?Span
    {
        return SentrySdk::getIsolationScope()->getSpan();
    }

    /**
     * @return $this
     */
    public function setSpan(?Span $span): self
    {
        SentrySdk::getIsolationScope()->setSpan($span);

        return $this;
    }
}
