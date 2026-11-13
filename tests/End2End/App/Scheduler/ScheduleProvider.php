<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Scheduler;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Event\PreRunEvent;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ScheduleProvider implements ScheduleProviderInterface
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    public function __construct(string $name, EventDispatcherInterface $dispatcher)
    {
        $this->name = $name;
        $this->dispatcher = $dispatcher;
    }

    public function getSchedule(): Schedule
    {
        // Start from a minute ago, so the last run of the every minute schedules is due now
        $state = new ArrayAdapter();
        $state->get('scheduler_checkpoint_' . $this->name, static function (): array {
            $lastRun = new \DateTimeImmutable('-1 minute');

            return [$lastRun, -1, $lastRun];
        });

        if ('unknown_transport' === $this->name) {
            return (new Schedule())
                ->stateful($state)
                ->add(RecurringMessage::cron('* * * * *', new RedispatchMessage(new ScheduledMessage(), 'unknown')));
        }

        if ('cancelled' === $this->name) {
            // Symfony 8.2+ keeps the listeners of before() on the schedule
            $schedule = class_exists('Symfony\Component\Messenger\Stamp\RedispatchStamp') ? new Schedule() : new Schedule($this->dispatcher);

            return $schedule
                ->stateful($state)
                ->add(RecurringMessage::cron('* * * * *', new ScheduledMessage()))
                ->before(static function (PreRunEvent $event): void {
                    $event->shouldCancel(true);
                });
        }

        if ('redispatch' === $this->name) {
            return (new Schedule())
                ->stateful($state)
                ->add(RecurringMessage::cron('* * * * *', new RedispatchMessage(new ScheduledMessage(), 'async')));
        }

        return (new Schedule())
            ->stateful($state)
            ->add(
                RecurringMessage::cron('* * * * *', new ScheduledMessage()),
                RecurringMessage::cron('* * * * *', new FailingScheduledMessage())
            );
    }
}
