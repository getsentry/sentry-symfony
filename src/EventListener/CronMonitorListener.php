<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\CheckInStatus;
use Sentry\State\HubInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;

class CronMonitorListener
{
    /**
     * @var HubInterface
     */
    private $hub;

    /**
     * @var array<string>
     */
    private $registeredCommands;

    /**
     * @param HubInterface  $hub
     * @param array<string> $registeredCommands
     */
    public function __construct(HubInterface $hub, array $registeredCommands = [])
    {
        $this->hub = $hub;
        $this->registeredCommands = $registeredCommands;
    }

    public function handleConsoleCommandEvent(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();

        if (false === $this->isValid($command)) {
            return;
        }

        $checkinId = $this->hub->captureCheckIn(
            $this->registeredCommands[$this->getCommandIndex($command)],
            CheckInStatus::inProgress()
        );
    }

    public function handleConsoleTerminateEvent(ConsoleTerminateEvent $event): void
    {
        $command = $event->getCommand();

        if (false === $this->isValid($command)) {
            return;
        }

        $this->hub->captureCheckIn(
            $this->registeredCommands[$this->getCommandIndex($command)],
            Command::SUCCESS === $event->getExitCode()
                ? CheckInStatus::ok()
                : CheckInStatus::error()
        );
    }

    public function handleConsoleErrorEvent(ConsoleErrorEvent $event): void
    {
        $command = $event->getCommand();

        if (false === $this->isValid($command)) {
            return;
        }

        $this->hub->captureCheckIn(
            $this->registeredCommands[$this->getCommandIndex($command)],
            CheckInStatus::error()
        );
    }

    private function isValid(?Command $command): bool
    {
        return $command instanceof Command && isset($this->registeredCommands[$this->getCommandIndex($command)]);
    }

    private function getCommandIndex(?Command $command): string
    {
        if (null === $command) {
            return '';
        }

        if (\PHP_VERSION > 8.0) {
            return $command::class;
        }

        return \get_class($command);
    }
}
