<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Event;
use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

if (!class_exists(KernelBrowser::class) && class_exists(Client::class)) {
    class_alias(Client::class, KernelBrowser::class);
}

/**
 * @runTestsInSeparateProcesses
 */
final class DataCollectionUserInfoEnd2EndTest extends WebTestCase
{
    private const CLIENT_IP = '1.2.3.4';

    /**
     * @param array{extra_config_files?: list<string>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new KernelWithExtraConfig(array_merge([
            __DIR__ . '/App/tracing.yml',
        ], $options['extra_config_files'] ?? []));
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubTransport::$events = [];
    }

    /**
     * @param list<string> $extraConfigFiles
     *
     * @dataProvider userInfoDataProvider
     */
    public function testUserInfoIsOnlyCollectedWhenEnabled(array $extraConfigFiles, ?string $expectedIpAddress): void
    {
        $client = static::createClient([
            'debug' => false,
            'extra_config_files' => $extraConfigFiles,
        ]);

        $client->request('GET', '/exception', [], [], ['REMOTE_ADDR' => self::CLIENT_IP]);

        $errorEvent = $this->getEvent(false);
        $user = $errorEvent->getUser();

        $this->assertSame($expectedIpAddress, null === $user ? null : $user->getIpAddress());

        /** @var array<string, mixed> $transactionData */
        $transactionData = $this->getEvent(true)->getContexts()['trace']['data'] ?? [];

        $this->assertSame($expectedIpAddress, $transactionData['net.peer.ip'] ?? null);
    }

    public function userInfoDataProvider(): \Generator
    {
        yield 'send_default_pii is disabled' => [
            [],
            null,
        ];

        yield 'send_default_pii is enabled' => [
            [__DIR__ . '/App/send_default_pii.yml'],
            self::CLIENT_IP,
        ];

        yield 'data_collection.user_info is enabled by default' => [
            [__DIR__ . '/App/config/data_collection/defaults.yml'],
            self::CLIENT_IP,
        ];

        yield 'data_collection.user_info is disabled while send_default_pii is enabled' => [
            [__DIR__ . '/App/config/data_collection/user_info_disabled.yml'],
            null,
        ];
    }

    private function getEvent(bool $isTransaction): Event
    {
        $events = array_values(array_filter(StubTransport::$events, static function (Event $event) use ($isTransaction): bool {
            return $isTransaction === (null !== $event->getTransaction());
        }));

        $this->assertCount(1, $events);

        return $events[0];
    }
}
