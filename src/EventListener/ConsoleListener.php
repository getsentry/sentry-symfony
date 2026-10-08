<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\EventListener;

use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\DataCollection\KeyValueCollectionBehavior;
use Sentry\DataCollection\KeyValueDataFilter;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionMechanism;
use Sentry\Logs\Logs;
use Sentry\Metrics\TraceMetrics;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Exception\ExceptionInterface;
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
            $scope->setExtra('Full command', $this->getFullCommand($input, $command));
        }
    }

    /**
     * Gets the command as it was run. The data collection options filter the values
     * of arguments and options with a sensitive name, e.g. "app:import --password=secret"
     * becomes "app:import --password=[Filtered]".
     */
    private function getFullCommand(ArgvInput $input, ?Command $command): string
    {
        $fullCommand = (string) $input;

        if (DataCollectionPolicy::fromHub($this->hub)->isLegacyMode()) {
            return $fullCommand;
        }

        // The tokens after an invalid one, e.g. an unknown option, are not parsed,
        // so it is unknown whether their values are sensitive so we assume they are.
        if (null !== $command && !self::isValidInput($input, $command)) {
            return $input->escapeToken((string) $command->getName()) . ' ' . KeyValueDataFilter::FILTERED_VALUE;
        }

        $parameters = array_merge($input->getArguments(), $input->getOptions());

        $filteredParameters = (new KeyValueDataFilter(KeyValueCollectionBehavior::denyList()))->filterKeyValueData($parameters);
        if (null === $filteredParameters) {
            return $fullCommand;
        }

        foreach ($filteredParameters as $name => $value) {
            if (KeyValueDataFilter::FILTERED_VALUE !== $value) {
                continue;
            }

            foreach ((array) $parameters[$name] as $sensitiveValue) {
                if (!\is_string($sensitiveValue) || '' === $sensitiveValue) {
                    continue;
                }

                // The value is either its own token, follows a "=" or shortcuts, e.g. "--password secret", "--password=secret" or "-fpsecret"
                $pattern = '/(^|\s|=|\s-[a-zA-Z]+)' . preg_quote($input->escapeToken($sensitiveValue), '/') . '(?=\s|$)/';
                $fullCommand = preg_replace($pattern, '$1' . KeyValueDataFilter::FILTERED_VALUE, $fullCommand) ?? $fullCommand;
            }
        }

        return $fullCommand;
    }

    private static function isValidInput(ArgvInput $input, Command $command): bool
    {
        try {
            // A copy is bound to not change the input of the command
            (clone $input)->bind($command->getDefinition());
        } catch (ExceptionInterface $exception) {
            return false;
        }

        return true;
    }

    /**
     * Handles the termination of a console command by popping the {@see Scope}.
     *
     * @param ConsoleTerminateEvent $event The event
     */
    public function handleConsoleTerminateEvent(ConsoleTerminateEvent $event): void
    {
        Logs::getInstance()->flush();
        TraceMetrics::getInstance()->flush();
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
