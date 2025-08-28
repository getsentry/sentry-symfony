<?php

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class LoggingController
{

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function justLogging()
    {
        // LogLevel::fatal()
        $this->logger->emergency("Emergency Log");
        $this->logger->critical("Critical Log");
        // LogLevel::error()
        $this->logger->error("Error Log");
        // LogLevel::warn()
        $this->logger->warning("Warn Log");
        // LogLevel::info()
        $this->logger->info("Info Log");
        $this->logger->notice("Notice Log");
        // LogLevel::debug()
        $this->logger->debug("Debug Log");

        return new Response();
    }

    public function loggingWithError()
    {
        $this->logger->emergency("Something is not right");
        $this->logger->error("About to crash");
        throw new \RuntimeException("Crash");
    }

}
