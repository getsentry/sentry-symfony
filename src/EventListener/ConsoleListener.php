<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionMechanism;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArgvInput;

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
     * @var bool Whether to capture console errors
     */
    private $captureErrors;

    /**
     * Constructor.
     *
     * @param HubInterface $hub           The current hub
     * @param bool         $captureErrors Whether to capture console errors
     */
    public function __construct(HubInterface $hub, bool $captureErrors = true)
    {
        $this->hub = $hub;
        $this->captureErrors = $captureErrors;
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
        $input = $event->getInput();

        if (null !== $command && null !== $command->getName()) {
            $scope->setTag('console.command', $command->getName());
        }

        if ($input instanceof ArgvInput) {
            $scope->setExtra('Full command', (string) $input);
        }
    }

    /**
     * Handles the termination of a console command by popping the {@see Scope}.
     *
     * @param ConsoleTerminateEvent $event The event
     */
    public function handleConsoleTerminateEvent(ConsoleTerminateEvent $event): void
    {
        // The scope is popped here with a low priority (-128) to ensure that
        // other listeners (like TracingConsoleListener at priority -54) have
        // a chance to capture breadcrumbs and finish transactions before the
        // scope is removed.
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

            if ($this->captureErrors) {
                $hint = EventHint::fromArray([
                    'exception' => $event->getError(),
                    'mechanism' => new ExceptionMechanism(ExceptionMechanism::TYPE_GENERIC, false),
                ]);

                $this->hub->captureEvent(Event::createEvent(), $hint);
            }
        });
    }
}
