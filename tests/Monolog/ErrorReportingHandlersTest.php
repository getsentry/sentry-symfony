<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Monolog;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\ClientInterface;
use Sentry\Exception\SilencedErrorException;
use Sentry\Options;
use Sentry\SentryBundle\Monolog\CapturedExceptionTracker;
use Sentry\SentryBundle\Monolog\PhpErrorHandler;
use Sentry\SentryBundle\Monolog\UncaughtExceptionHandler;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Symfony\Component\ErrorHandler\Exception\SilencedErrorContext;

/**
 * Which channels the handlers are pushed to is covered by MonologErrorHandlerEnd2EndTest.
 */
final class ErrorReportingHandlersTest extends TestCase
{
    /**
     * @var HubInterface&MockObject
     */
    private $hub;

    /**
     * @var CapturedExceptionTracker
     */
    private $tracker;

    /**
     * @var list<\Throwable>
     */
    private $captured = [];

    /**
     * @var Options
     */
    private $options;

    protected function setUp(): void
    {
        $this->options = new Options(['error_types' => \E_ALL]);

        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturnCallback(function (): Options {
            return $this->options;
        });

        $this->hub = $this->createMock(HubInterface::class);
        $this->hub->method('getClient')->willReturn($client);
        $this->hub->method('withScope')->willReturnCallback(static function (callable $callback) {
            return $callback(new Scope());
        });
        $this->hub->method('captureException')->willReturnCallback(function (\Throwable $exception) {
            $this->captured[] = $exception;

            return null;
        });

        $this->tracker = new CapturedExceptionTracker();
    }

    public function testUncaughtExceptionsOfTheKernelAndOfCommandsAreCaptured(): void
    {
        $handler = new UncaughtExceptionHandler($this->hub, $this->tracker);

        (new Logger('request', [$handler]))->critical('Uncaught PHP Exception', ['exception' => $requestException = new \RuntimeException()]);
        (new Logger('console', [$handler]))->critical('Error thrown while running command', ['exception' => $consoleException = new \RuntimeException()]);

        $this->assertSame([$requestException, $consoleException], $this->captured);
    }

    public function testRecordsWithoutExceptionOrBelowErrorAreNotCaptured(): void
    {
        $logger = new Logger('request', [new UncaughtExceptionHandler($this->hub, $this->tracker)]);
        $logger->critical('No exception attached');
        $logger->critical('Not an exception', ['exception' => 'a string']);
        $logger->warning('Below ERROR', ['exception' => new \RuntimeException()]);

        $this->assertSame([], $this->captured);
    }

    public function testMessengerRetriesFollowCaptureSoftFails(): void
    {
        $context = ['exception' => $exception = new \RuntimeException(), 'retryCount' => 1, 'delay' => 1000];

        $logger = new Logger('messenger', [new UncaughtExceptionHandler($this->hub, $this->tracker, false)]);
        $logger->warning('Sending for retry', $context);
        $logger->error('Sending for retry', $context);
        $this->assertSame([], $this->captured);

        (new Logger('messenger', [new UncaughtExceptionHandler($this->hub, $this->tracker, true)]))->warning('Sending for retry', $context);
        $this->assertSame([$exception], $this->captured);
    }

    public function testRecordsBelowTheLevelOfTheUncaughtExceptionHandlerAreNotCreated(): void
    {
        $processorCalls = 0;
        $logger = new Logger('request', [new UncaughtExceptionHandler($this->hub, $this->tracker, false)]);
        $logger->pushProcessor(static function ($record) use (&$processorCalls) {
            ++$processorCalls;

            return $record;
        });

        $logger->debug('debug');
        $logger->info('info');
        $logger->warning('warning');

        $this->assertSame(0, $processorCalls, 'Monolog created records although no handler handles them');
    }

    public function testDeprecationsAreCapturedRegardlessOfTheirLogLevel(): void
    {
        $logger = new Logger('php', [new PhpErrorHandler($this->hub, $this->tracker)]);
        $logger->info('User Deprecated: foo', ['exception' => $exception = new \ErrorException('User Deprecated: foo', 0, \E_USER_DEPRECATED)]);
        $logger->info('No exception attached');

        $this->assertSame([$exception], $this->captured);
    }

    /**
     * @dataProvider silencedErrorsProvider
     *
     * @param SilencedErrorContext|\ErrorException $error
     */
    public function testSilencedErrorsOnlyFollowCaptureSilencedErrorsLikeTheSdk($error, bool $captureSilencedErrors, ?string $expectedType): void
    {
        // Default `error_types`, which follows error_reporting(): it must not filter silenced errors out
        $this->options = new Options(['capture_silenced_errors' => $captureSilencedErrors]);
        $logger = new Logger('php', [new PhpErrorHandler($this->hub, $this->tracker)]);

        // Like the @ operator does while the error is handled
        $errorReporting = error_reporting(0);

        try {
            $logger->info('Silenced', ['exception' => $error]);
        } finally {
            error_reporting($errorReporting);
        }

        $this->assertSame(null === $expectedType ? [] : [$expectedType], array_map('get_class', $this->captured));
    }

    /**
     * @return iterable<string, array{SilencedErrorContext|\ErrorException, bool, ?string}>
     */
    public static function silencedErrorsProvider(): iterable
    {
        $warning = new SilencedErrorContext(\E_USER_WARNING, __FILE__, __LINE__);
        $deprecation = new \ErrorException('User Deprecated: foo', 0, \E_USER_DEPRECATED);

        yield 'silenced warning, captured' => [$warning, true, SilencedErrorException::class];
        yield 'silenced warning, not captured' => [$warning, false, null];
        yield 'silenced deprecation, captured' => [$deprecation, true, SilencedErrorException::class];
        yield 'silenced deprecation, not captured' => [$deprecation, false, null];
    }

    public function testUnsilenceableErrorsAreNotReportedAsSilenced(): void
    {
        if (\PHP_VERSION_ID < 80000) {
            $this->markTestSkipped('Errors of all levels can be silenced before PHP 8.');
        }

        $this->options = new Options(['capture_silenced_errors' => false]);
        $logger = new Logger('php', [new PhpErrorHandler($this->hub, $this->tracker)]);

        // What error_reporting() returns while the @ operator is active since PHP 8
        $errorReporting = error_reporting(\E_ERROR | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR | \E_RECOVERABLE_ERROR | \E_PARSE);

        try {
            $logger->critical('User Error: foo', ['exception' => $exception = new \ErrorException('User Error: foo', 0, \E_USER_ERROR)]);
        } finally {
            error_reporting($errorReporting);
        }

        $this->assertSame([$exception], $this->captured);
    }

    public function testSilencedErrorsDoNotReachOtherHandlersWhenTheyMustNotBubble(): void
    {
        $nextHandler = new TestHandler();
        $logger = new Logger('php', [new PhpErrorHandler($this->hub, $this->tracker, false), $nextHandler]);

        $logger->debug('User Warning: silenced', ['exception' => new SilencedErrorContext(\E_USER_WARNING, __FILE__, __LINE__)]);

        $this->assertSame([], $nextHandler->getRecords());
    }

    public function testOtherRecordsReachOtherHandlersWhenSilencedErrorsMustNotBubble(): void
    {
        $nextHandler = new TestHandler();
        $logger = new Logger('php', [new PhpErrorHandler($this->hub, $this->tracker, false), $nextHandler]);

        // Symfony always logs deprecations, silenced or not
        $logger->info('User Deprecated: foo', ['exception' => new \ErrorException('User Deprecated: foo', 0, \E_USER_DEPRECATED)]);
        $logger->info('No exception attached');

        $this->assertCount(2, $nextHandler->getRecords());
    }

    public function testSilencedErrorsReachOtherHandlersWhenTheyMustBubble(): void
    {
        $nextHandler = new TestHandler();
        $logger = new Logger('php', [new PhpErrorHandler($this->hub, $this->tracker, true), $nextHandler]);

        $logger->debug('User Warning: silenced', ['exception' => new SilencedErrorContext(\E_USER_WARNING, __FILE__, __LINE__)]);

        $this->assertCount(1, $nextHandler->getRecords());
    }

    public function testSameExceptionLoggedToBothHandlersIsCapturedOnce(): void
    {
        $exception = new \RuntimeException();

        (new Logger('php', [new PhpErrorHandler($this->hub, $this->tracker)]))->critical('Fatal Error', ['exception' => $exception]);
        (new Logger('request', [new UncaughtExceptionHandler($this->hub, $this->tracker)]))->critical('Uncaught PHP Exception', ['exception' => $exception]);

        $this->assertSame([$exception], $this->captured);
    }
}
