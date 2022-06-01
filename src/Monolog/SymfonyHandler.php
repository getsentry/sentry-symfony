<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Monolog;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\ResettableInterface;
use Sentry\Monolog\Handler;
use Symfony\Component\ErrorHandler\Error\FatalError;

class SymfonyHandler implements HandlerInterface, ProcessableHandlerInterface, FormattableHandlerInterface, ResettableInterface
{
    /**
     * @var Handler
     */
    private $decoratedHandler;

    public function __construct(Handler $decoratedHandler)
    {
        $this->decoratedHandler = $decoratedHandler;
    }

    public function setFormatter(FormatterInterface $formatter): HandlerInterface
    {
        return $this->decoratedHandler->setFormatter($formatter);
    }

    public function getFormatter(): FormatterInterface
    {
        return $this->decoratedHandler->getFormatter();
    }

    public function isHandling(array $record): bool
    {
        return $this->decoratedHandler->isHandling($record);
    }

    public function handleBatch(array $records): void
    {
        $this->decoratedHandler->handleBatch($records);
    }

    public function close(): void
    {
        $this->decoratedHandler->close();
    }

    public function pushProcessor(callable $callback): HandlerInterface
    {
        return $this->decoratedHandler->pushProcessor($callback);
    }

    public function popProcessor(): callable
    {
        return $this->decoratedHandler->popProcessor();
    }

    public function reset(): void
    {
        $this->decoratedHandler->reset();
    }

    public function handle(array $record): bool
    {
        $exception = $record['exception'] ?? null;

        if ($exception instanceof FatalError) {
            return false;
        }

        return $this->decoratedHandler->handle($record);
    }
}
