<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class BufferFlushController
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function testBufferFlush(): Response
    {
        $this->logger->notice('Test notice message');
        $this->logger->warning('Test warning message');
        $this->logger->error('Test error message');

        return new Response('OK');
    }
}
