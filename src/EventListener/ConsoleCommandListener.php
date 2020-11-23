<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Event\ConsoleErrorEvent;

/**
 * This listener handles all errors thrown while running a console command and
 * logs them to Sentry.
 */
final class ConsoleCommandListener
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
     * Handles an error that happened while running a console command.
     *
     * @param ConsoleErrorEvent $event The event
     */
    public function handleConsoleErrorEvent(ConsoleErrorEvent $event): void
    {
        $this->hub->withScope(function (Scope $scope) use ($event): void {
            $command = $event->getCommand();

            if (null !== $command && null !== $command->getName()) {
                $scope->setTag('console.command', $command->getName());
            }

            $scope->setTag('console.command.exit_code', (string) $event->getExitCode());

            $this->hub->captureException($event->getError());
        });
    }
}
