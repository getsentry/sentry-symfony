<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Cron;

use Sentry\SentrySdk;

class CronJobFactory implements CronJobFactoryInterface
{
    /**
     * @var string
     */
    private $environment;
    /**
     * @var string
     */
    private $release;

    /**
     * @param string $environment the configured environment
     */
    public function __construct(string $environment, string $release)
    {
        $this->environment = $environment;
        $this->release = $release;
    }

    /**
     * {@inheritdoc}
     */
    public function getCronJob(string $slug): CronJobInterface
    {
        $hub = SentrySdk::getCurrentHub();
        return new CronJob($hub, $slug, $this->environment, $this->release);
    }
}
