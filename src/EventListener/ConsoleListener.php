<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;

/**
 * This listener handles all errors thrown while running a console command and
 * logs them to Sentry.
 *
 * @final since version 4.1
 */
class ConsoleListener
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * Constructor.
     *
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * Handles the execution of a console command by pushing a new {@see Scope}.
     *
     * @param ConsoleCommandEvent $event The event
     */
    public function handleConsoleCommandEvent(ConsoleCommandEvent $event): void
    {
        $scope = $this->hub->pushScope();
        $command = $event->getCommand();

        if (null !== $command && null !== $command->getName()) {
            $scope->setTag('console.command', $command->getName());
        }
    }

    /**
     * Handles the termination of a console command by popping the {@see Scope}.
     *
     * @param ConsoleTerminateEvent $event The event
     */
    public function handleConsoleTerminateEvent(ConsoleTerminateEvent $event): void
    {
        $this->hub->popScope();
    }

    /**
     * Handles an error that happened while running a console command.
     *
     * @param ConsoleErrorEvent $event The event
     */
    public function handleConsoleErrorEvent(ConsoleErrorEvent $event): void
    {
        $this->hub->configureScope(function (Scope $scope) use ($event): void {
            $scope->setTag('console.command.exit_code', (string) $event->getExitCode());

            $this->hub->captureException($event->getError());
        });
    }
}
