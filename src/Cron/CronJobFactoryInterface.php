<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Cron;

interface CronJobFactoryInterface
{
    /**
     * @param string $slug the cronjobs monitor slug
     *
     * @return CronJobInterface the cronjob
     */
    public function getCronjob(string $slug): CronJobInterface;
}
