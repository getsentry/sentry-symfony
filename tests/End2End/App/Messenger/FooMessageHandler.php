<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

class FooMessageHandler
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(FooMessage $message): void
    {
        $this->logger->warning('Handle FooMessage');
        if (!$message->shouldRetry()) {
            throw new class extends \Exception implements UnrecoverableExceptionInterface {
            };
        }

        throw new \Exception('This is an intentional failure while handling a message of class ' . \get_class($message));
    }
}
