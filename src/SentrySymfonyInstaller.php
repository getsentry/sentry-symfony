<?php

namespace Sentry\SentryBundle;

class SentrySymfonyInstaller
{
    /**
     * @var bool
     */
    protected $installed = false;
    /**
     * @var \Raven_Client
     */
    private $client;
    /**
     * @var bool
     */
    private $enabled;

    /**
     * SentrySymfonyInstaller constructor.
     * @param \Raven_Client $client
     * @param bool $enabled
     */
    public function __construct(\Raven_Client $client, $enabled = true)
    {
        $this->client = $client;
        $this->enabled = $enabled;
    }

    /**
     * @throws \Raven_Exception
     */
    public function install()
    {
        if ($this->installed || false === $this->enabled) {
            return;
        }
        $this->client->install();
        $this->installed = true;
    }

    /**
     * @return bool
     */
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
