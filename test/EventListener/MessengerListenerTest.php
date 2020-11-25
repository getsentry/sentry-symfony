<?php

namespace Sentry\SentryBundle\Test\EventListener;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\SentryBundle\EventListener\MessengerListener;
use Sentry\SentryBundle\Test\End2End\App\Kernel;
use Sentry\State\HubInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class MessengerListenerTest extends TestCase
{
    /**
     * @var MockObject&ClientInterface
     */
    private $client;

    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ClientInterface::class);
        $this->hub = $this->createMock(HubInterface::class);
    }

    /**
     * @dataProvider handleWorkerMessageFailedEventDataProvider
     *
     * @param \Throwable[] $exceptions
     */
    public function testHandleWorkerMessageFailedEvent(array $exceptions, WorkerMessageFailedEvent $event): void
    {
        if (! $this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $this->hub->expects($this->exactly(count($exceptions)))
            ->method('captureException')
            ->withConsecutive(...array_map(static function (\Throwable $exception): array {
                return [$exception];
            }, $exceptions));

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $listener = new MessengerListener($this->hub);
        $listener->handleWorkerMessageFailedEvent($event);
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleWorkerMessageFailedEventDataProvider(): \Generator
    {
        if (! $this->supportsMessenger()) {
            return;
        }

        $envelope = Envelope::wrap((object) []);
        $exceptions = [
            new \Exception(),
            new \Exception(),
        ];

        yield [
            $exceptions,
            $this->getMessageFailedEvent($envelope, 'receiver', new HandlerFailedException($envelope, $exceptions), false),
        ];

        $exceptions = [
            new \Exception(),
        ];

        yield [
            $exceptions,
            $this->getMessageFailedEvent($envelope, 'receiver', $exceptions[0], false),
        ];
    }

    /**
     * @dataProvider handleWorkerMessageFailedEventWithCaptureSoftFailsFlagDataProvider
     */
    public function testHandleWorkerMessageFailedEventWithCaptureSoftFailsFlag(bool $captureSoftFails, bool $retry, bool $shouldCallFlush): void
    {
        if (! $this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $envelope = Envelope::wrap((object) []);
        $event = $this->getMessageFailedEvent($envelope, 'receiver', new \Exception(), $retry);

        $this->hub->expects($this->any())
            ->method('getClient')
            ->willReturn($this->client);

        $this->client->expects($shouldCallFlush ? $this->once() : $this->never())
            ->method('flush');

        $listener = new MessengerListener($this->hub, $captureSoftFails);
        $listener->handleWorkerMessageFailedEvent($event);
    }

    /**
     * @return \Generator<mixed>
     */
    public function handleWorkerMessageFailedEventWithCaptureSoftFailsFlagDataProvider(): \Generator
    {
        yield '$captureSoftFails = FALSE && $willRetry = TRUE => KO' => [
            false,
            true,
            false,
        ];

        yield '$captureSoftFails = FALSE && $willRetry = FALSE => OK' => [
            false,
            false,
            true,
        ];

        yield '$captureSoftFails = TRUE && $willRetry = TRUE => OK' => [
            false,
            false,
            true,
        ];

        yield '$captureSoftFails = TRUE && $willRetry = FALSE => OK' => [
            false,
            false,
            true,
        ];
    }

    public function testHandleWorkerMessageHandledEvent(): void
    {
        if (! $this->supportsMessenger()) {
            $this->markTestSkipped('Messenger not supported in this environment.');
        }

        $this->hub->expects($this->once())
            ->method('getClient')
            ->willReturn($this->client);

        $this->client->expects($this->once())
            ->method('flush');

        $listener = new MessengerListener($this->hub);
        $listener->handleWorkerMessageHandledEvent(new WorkerMessageHandledEvent(Envelope::wrap((object) []), 'receiver'));
    }

    private function getMessageFailedEvent(Envelope $envelope, string $receiverName, \Throwable $error, bool $retry): WorkerMessageFailedEvent
    {
        if (version_compare(Kernel::VERSION, '4.4.0', '<')) {
            return new WorkerMessageFailedEvent($envelope, $receiverName, $error, $retry);
        }

        $event = new WorkerMessageFailedEvent($envelope, $receiverName, $error);

        if ($retry) {
            $event->setForRetry();
        }

        return $event;
    }

    private function supportsMessenger(): bool
    {
        return interface_exists(MessageBusInterface::class);
    }
}
