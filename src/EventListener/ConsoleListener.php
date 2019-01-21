<?php

namespace Sentry\SentryBundle\EventListener;

use Sentry\State\Hub;
use Sentry\State\HubInterface;
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

        Hub::getCurrent()
            ->getScope()
            ->setTag('command', $command ? $command->getName() : 'N/A');
    }
}
