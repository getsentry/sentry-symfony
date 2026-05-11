<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\Event;
use Sentry\Logs\Log;
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
final class LoggingFlushThresholdEnd2EndTest extends WebTestCase
{
    /**
     * @param array{extra_config_files?: list<string>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new KernelWithExtraConfig(array_merge([
            __DIR__ . '/App/logging.yml',
        ], $options['extra_config_files'] ?? []));
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubTransport::$events = [];
    }

    public function testLoggerFlushesLogsWhenThresholdIsReached(): void
    {
        $client = static::createClient([
            'extra_config_files' => [__DIR__ . '/App/config/log_flush_threshold.yml'],
        ]);

        $client->request('GET', '/just-logging');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $logs = $this->getFilteredLogBodies();

        $this->assertCount(2, $logs);
        $this->assertSame(['Emergency Log', 'Critical Log'], $logs[0]);
        $this->assertSame(['Error Log', 'Warn Log'], $logs[1]);
    }

    public function testLoggerDoesNotFlushLogsWhenThresholdIsNull(): void
    {
        $client = static::createClient([
            'extra_config_files' => [__DIR__ . '/App/config/log_flush_threshold_null.yml'],
        ]);

        $client->request('GET', '/just-logging');

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $logs = $this->getFilteredLogBodies();

        $this->assertCount(1, $logs);
        $this->assertSame(['Emergency Log', 'Critical Log', 'Error Log', 'Warn Log'], $logs[0]);
    }

    /**
     * Removes framework logs so that the tests can focus on our expected logs.
     *
     * @param Log[] $logs
     *
     * @return Log[]
     */
    private function filterFrameworkLogs(array $logs): array
    {
        return array_values(array_filter($logs, static function (Log $log): bool {
            return 'Matched route "{route}".' !== $log->getBody()
                && 'Notified event "{event}" to listener "{listener}".' !== $log->getBody();
        }));
    }

    /**
     * @return array<int, string[]>
     */
    private function getFilteredLogBodies(): array
    {
        return array_values(array_filter(array_map(function (Event $event): array {
            return array_map(static function (Log $log): string {
                return $log->getBody();
            }, $this->filterFrameworkLogs($event->getLogs()));
        }, StubTransport::$events), static function (array $logs): bool {
            return \count($logs) > 0;
        }));
    }
}
