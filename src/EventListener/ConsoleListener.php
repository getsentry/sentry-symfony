<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

final class ConsoleListener
{
    /** @var HubInterface */
    private $hub;

    /**
     * ConsoleListener constructor.
     * @param HubInterface $hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub; // not used, needed to trigger instantiation
    }

    /**
     * This method ensures that the client and error handlers are registered at the start of the command
     * execution cycle, and not only on exceptions
     *
     * @param ConsoleCommandEvent $event
     *
     * @return void
     */
    public function onConsoleCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        $commandName = null;
        if ($command) {
            $commandName = $command->getName();
        }

        SentrySdk::getCurrentHub()
            ->configureScope(static function (Scope $scope) use ($commandName): void {
                $scope->setTag('command', $commandName ?? 'N/A');
            });
    }
}
