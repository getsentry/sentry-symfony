<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End\App\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * @see \Symfony\Component\Messenger\Transport\InMemoryTransport
 */
class StaticInMemoryTransport implements TransportInterface
{
    /**
     * @var Envelope[]
     */
    private static $sent = [];

    /**
     * @var Envelope[]
     */
    private static $acknowledged = [];

    /**
     * @var Envelope[]
     */
    private static $rejected = [];

    /**
     * @var Envelope[]
     */
    private static $queue = [];

    /**
     * {@inheritdoc}
     */
    public function get(): iterable
    {
        return array_values(self::$queue);
    }

    /**
     * {@inheritdoc}
     */
    public function ack(Envelope $envelope): void
    {
        self::$acknowledged[] = $envelope;
        $id = spl_object_hash($envelope->getMessage());
        unset(self::$queue[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(Envelope $envelope): void
    {
        self::$rejected[] = $envelope;
        $id = spl_object_hash($envelope->getMessage());
        unset(self::$queue[$id]);
    }

    /**
     * {@inheritdoc}
     */
    public function send(Envelope $envelope): Envelope
    {
        self::$sent[] = $envelope;
        $id = spl_object_hash($envelope->getMessage());
        self::$queue[$id] = $envelope;

        return $envelope;
    }

    public static function reset(): void
    {
        self::$sent = self::$queue = self::$rejected = self::$acknowledged = [];
    }

    /**
     * @return Envelope[]
     */
    public function getAcknowledged(): array
    {
        return self::$acknowledged;
    }

    /**
     * @return Envelope[]
     */
    public function getRejected(): array
    {
        return self::$rejected;
    }

    /**
     * @return Envelope[]
     */
    public function getSent(): array
    {
        return self::$sent;
    }
}
