<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Scheduler;

use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

class ScheduleProvider implements ScheduleProviderInterface
{
    /**
     * @var string
     */
    private $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getSchedule(): Schedule
    {
        // Start from a minute ago, so the last run of the every minute schedules is due now
        $state = new ArrayAdapter();
        $state->get('scheduler_checkpoint_' . $this->name, static function (): array {
            $lastRun = new \DateTimeImmutable('-1 minute');

            return [$lastRun, -1, $lastRun];
        });

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
