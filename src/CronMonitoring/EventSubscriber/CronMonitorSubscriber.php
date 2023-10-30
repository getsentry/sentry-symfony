<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\CronMonitoring\EventSubscriber;

use Sentry\SentryBundle\CronMonitoring\CronMonitor;
use Sentry\SentryBundle\CronMonitoring\CronMonitorFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CronMonitorSubscriber implements EventSubscriberInterface
{
    /**
     * @var CronMonitorFactory
     */
    private $cronMonitorFactory;

    /**
     * @var ?CronMonitor
     */
    private $cronMonitor = null;

    public function __construct(CronMonitorFactory $cronMonitorFactory)
    {
        $this->cronMonitorFactory = $cronMonitorFactory;
    }

    public function onConsoleCommandStart(ConsoleCommandEvent $event)
    {
        if (!$event->getInput()->hasOption('cron-monitor-slug')) {
            return; // Cron monitor not enabled in application
        }
        $slug = $event->getInput()->getOption('cron-monitor-slug');
        $schedule = $event->getInput()->getOption('cron-monitor-schedule');
        $maxTime = $event->getInput()->getOption('cron-monitor-max-time');
        $checkMargin = $event->getInput()->getOption('cron-monitor-check-margin');

        if ($slug && $schedule) {
            $this->cronMonitor = $this->cronMonitorFactory->create($slug, $schedule, $checkMargin ? (int) $checkMargin : null, $maxTime ? (int) $maxTime : null);
            $this->cronMonitor->start();
        }
    }

    public function onConsoleCommandTerminate(ConsoleTerminateEvent $event)
    {
        if ($this->cronMonitor) {
            if (Command::SUCCESS === $event->getExitCode()) {
                $this->cronMonitor->finishSuccess();
            } else {
                $this->cronMonitor->finishError();
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'onConsoleCommandStart',
            ConsoleEvents::TERMINATE => 'onConsoleCommandTerminate',
        ];
    }
}
