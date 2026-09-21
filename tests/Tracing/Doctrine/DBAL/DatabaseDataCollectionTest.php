<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Doctrine\DBAL;

use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\MockObject\MockObject;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\Tests\DoctrineTestCase;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\AbstractTracingStatement;
use Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingStatement;
use Sentry\State\Hub;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;

final class DatabaseDataCollectionTest extends DoctrineTestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!self::isDoctrineDBALInstalled()) {
            self::markTestSkipped('This test requires Doctrine DBAL.');
        }
    }

    public function testExistingStyleSubclassTraceFunctionCallsForwardAllArguments(): void
    {
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $statement = new ExistingStyleTracingStatement(
            $hub,
            $this->createMock(Statement::class),
            'SELECT :name',
            ['db.system' => 'sqlite']
        );
        $callback = static function (...$args): array {
            return $args;
        };

        $this->assertSame([], $statement->traceUsingExistingConvention($callback));
        $this->assertSame([null], $statement->traceUsingExistingConvention($callback, null));
        $this->assertSame([[':name' => 'Alice']], $statement->traceUsingExistingConvention($callback, [':name' => 'Alice']));
    }

    public function testBindingsAreCollectedOnlyOnSampledExecutionSpans(): void
    {
        $options = new Options(['data_collection' => [], 'send_default_pii' => false]);
        $hub = $this->createHub($options);
        $driver = $this->createDriverStatement(true);
        $statement = new TracingStatement($hub, $driver, 'SELECT :name, :password', ['db.system' => 'sqlite']);

        $statement->bindValue(':name', ['first' => 'Alice'], ParameterType::STRING);
        $statement->bindValue(':password', 'secret', ParameterType::STRING);

        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);

        $data = $this->executionSpans($transaction)[0]->getData();
        $this->assertSame('sqlite', $data['db.system']);
        $this->assertSame(['first' => 'Alice'], $data['db.query.parameter.:name']);
        $this->assertSame('[Filtered]', $data['db.query.parameter.:password']);
    }

    /**
     * @dataProvider disabledConfigurationProvider
     *
     * @param array<string, mixed>|null $configuration
     */
    public function testLegacyAndDisabledConfigurationsDoNotCollect(?array $configuration): void
    {
        $options = new Options([
            'send_default_pii' => true,
            'data_collection' => $configuration,
        ]);
        $hub = $this->createHub($options);
        $driver = $this->createDriverStatement(true);
        $statement = new TracingStatement($hub, $driver, 'SELECT ?', ['db.system' => 'sqlite']);
        $statement->bindValue(1, 'value', ParameterType::STRING);
        $transaction = $this->startTransaction($hub, true);

        $this->execute($statement);

        $this->assertSame(['db.system' => 'sqlite'], $this->executionSpans($transaction)[0]->getData());
    }

    public function disabledConfigurationProvider(): \Generator
    {
        yield 'null' => [null];
        yield 'explicit false' => [['database_query_data' => false]];
    }

    /**
     * @dataProvider unsampledProvider
     */
    public function testUnsampledAndUndecidedSpansDoNotCollect(?bool $sampled): void
    {
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true);
        $statement = new TracingStatement($hub, $driver, 'SELECT ?', ['db.system' => 'sqlite']);
        $statement->bindValue(1, new \stdClass(), ParameterType::STRING);
        $transaction = $this->startTransaction($hub, $sampled);

        $this->execute($statement);

        $this->assertSame(['db.system' => 'sqlite'], $this->executionSpans($transaction)[0]->getData());
    }

    public function unsampledProvider(): \Generator
    {
        yield 'unsampled' => [false];
        yield 'undecided' => [null];
    }

    public function testBindValueIsDetachedAndRepeatedExecutionsHaveSeparateSnapshots(): void
    {
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true, 2);
        $statement = new TracingStatement($hub, $driver, 'SELECT :profile', ['db.system' => 'sqlite']);
        $profile = ['name' => 'Alice'];
        $statement->bindValue(':profile', $profile, ParameterType::STRING);
        $profile['name'] = 'Changed outside';
        $transaction = $this->startTransaction($hub, true);

        $this->execute($statement);
        $statement->bindValue(':profile', ['name' => 'Bob'], ParameterType::STRING);
        $this->execute($statement);

        $spans = $this->executionSpans($transaction);
        $this->assertSame(['name' => 'Alice'], $spans[0]->getData()['db.query.parameter.:profile']);
        $this->assertSame(['name' => 'Bob'], $spans[1]->getData()['db.query.parameter.:profile']);
    }

    public function testBindParamReferencesAreReadAtEachExecutionAndCanBeReboundSafely(): void
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 does not support bindParam().');
        }

        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true, 4);
        $this->configureBindParam($driver, static function (): bool {
            return true;
        });
        $statement = new TracingStatement($hub, $driver, 'SELECT :profile', ['db.system' => 'sqlite']);
        $statement->bindValue(':profile', 'value binding', ParameterType::STRING);
        $firstName = 'Alice';
        $firstProfile = ['name' => &$firstName];
        $this->bindParam($statement, ':profile', $firstProfile);
        $transaction = $this->startTransaction($hub, true);

        $this->execute($statement);
        $firstName = 'Changed before second execution';
        $this->execute($statement);
        $secondName = 'Bob';
        $secondProfile = ['name' => &$secondName];
        $this->bindParam($statement, ':profile', $secondProfile);
        $this->assertSame('Changed before second execution', $firstName);
        $this->assertSame('Bob', $secondName);
        $this->execute($statement);
        $secondName = 'Changed after execution';
        $statement->bindValue(':profile', ['name' => 'detached value'], ParameterType::STRING);
        $this->execute($statement);

        $spans = $this->executionSpans($transaction);
        $this->assertSame(['name' => 'Alice'], $spans[0]->getData()['db.query.parameter.:profile']);
        $this->assertSame(['name' => 'Changed before second execution'], $spans[1]->getData()['db.query.parameter.:profile']);
        $this->assertSame(['name' => 'Bob'], $spans[2]->getData()['db.query.parameter.:profile']);
        $this->assertSame(['name' => 'detached value'], $spans[3]->getData()['db.query.parameter.:profile']);
        $this->assertSame('Changed before second execution', $firstName);
        $this->assertSame('Changed after execution', $secondName);
    }

    /**
     * @dataProvider parameterAliasProvider
     */
    public function testNamedAliasesReplaceBindingsWithoutChangingDriverArguments(string $first, string $second): void
    {
        $calls = [];
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true, 1, static function ($param, $value) use (&$calls): bool {
            $calls[] = [$param, $value];

            return true;
        });
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue($first, 'old', ParameterType::STRING);
        $statement->bindValue($second, 'new', ParameterType::STRING);
        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);

        $this->assertSame([[$first, 'old'], [$second, 'new']], $calls);
        $this->assertSame(['db.system' => 'sqlite', 'db.query.parameter.' . $second => 'new'], $this->executionSpans($transaction)[0]->getData());
    }

    public function parameterAliasProvider(): \Generator
    {
        yield 'without colon' => [':name', 'name'];
        yield 'with colon' => ['name', ':name'];
    }

    public function testNamedAndPositionalIdentifiersStayDistinct(): void
    {
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $statement = new TracingStatement($hub, $this->createDriverStatement(true), 'SELECT ?, :1', ['db.system' => 'sqlite']);
        $statement->bindValue(1, 'positional', ParameterType::STRING);
        $statement->bindValue(':1', 'named', ParameterType::STRING);
        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);

        $this->assertSame([
            'db.system' => 'sqlite',
            'db.query.parameter.1' => 'positional',
            'db.query.parameter.:1' => 'named',
        ], $this->executionSpans($transaction)[0]->getData());
    }

    public function testReferenceAliasesCanBeReplacedWithoutChangingCallerVariables(): void
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 does not support bindParam().');
        }
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true, 2);
        $this->configureBindParam($driver, static function (): bool { return true; });
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $first = 'first';
        $second = 'second';
        $this->bindParam($statement, ':name', $first);
        $this->bindParam($statement, 'name', $second);
        $second = 'current';
        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);
        $statement->bindValue(':name', 'value', ParameterType::STRING);
        $this->execute($statement);

        $spans = $this->executionSpans($transaction);
        $this->assertSame(['db.system' => 'sqlite', 'db.query.parameter.name' => 'current'], $spans[0]->getData());
        $this->assertSame(['db.system' => 'sqlite', 'db.query.parameter.:name' => 'value'], $spans[1]->getData());
        $this->assertSame('first', $first);
        $this->assertSame('current', $second);
    }

    public function testFailedBindValueDoesNotRecordOrOverwriteSuccessfulBinding(): void
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 bindValue() has no failure return value.');
        }

        $bindCalls = 0;
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true, 1, static function () use (&$bindCalls): bool {
            ++$bindCalls;

            return 1 === $bindCalls;
        });
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);

        $this->assertTrue($this->bindValueWithResult($statement, ':name', 'successful'));
        $this->assertFalse($this->bindValueWithResult($statement, ':name', 'failed'));
        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);

        $this->assertSame('successful', $this->executionSpans($transaction)[0]->getData()['db.query.parameter.:name']);
    }

    public function testFailedAndThrowingBindParamDoNotOverwriteSuccessfulReference(): void
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 does not support bindParam().');
        }

        $exception = new \RuntimeException('binding failed');
        $bindCalls = 0;
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true);
        $this->configureBindParam($driver, static function () use (&$bindCalls, $exception): bool {
            ++$bindCalls;
            if (3 === $bindCalls) {
                throw $exception;
            }

            return 1 === $bindCalls;
        });
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $successful = 'successful';
        $failed = 'failed';
        $throwing = 'throwing';

        $this->assertTrue($this->bindParam($statement, ':name', $successful));
        $this->assertFalse($this->bindParam($statement, ':name', $failed));
        try {
            $this->bindParam($statement, ':name', $throwing);
            $this->fail('Binding should throw.');
        } catch (\RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }

        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);
        $this->assertSame('successful', $this->executionSpans($transaction)[0]->getData()['db.query.parameter.:name']);
        $this->assertSame('successful', $successful);
        $this->assertSame('failed', $failed);
        $this->assertSame('throwing', $throwing);
    }

    public function testThrowingBindPreservesExceptionAndSuccessfulBinding(): void
    {
        $exception = new \RuntimeException('binding failed');
        $bindCalls = 0;
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true, 1, static function () use (&$bindCalls, $exception) {
            if (++$bindCalls > 1) {
                throw $exception;
            }

            return true;
        });
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':name', 'successful', ParameterType::STRING);

        try {
            $statement->bindValue(':name', 'throwing', ParameterType::STRING);
            $this->fail('Binding should throw.');
        } catch (\RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }

        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);
        $this->assertSame('successful', $this->executionSpans($transaction)[0]->getData()['db.query.parameter.:name']);
    }

    public function testTemporaryDisableDoesNotDiscardRetainedBindings(): void
    {
        $options = new Options(['data_collection' => []]);
        $hub = $this->createHub($options);
        $driver = $this->createDriverStatement(true, 2);
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':name', 'Alice', ParameterType::STRING);
        $options->updateOptions(['data_collection' => null]);
        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);

        $options->updateOptions(['data_collection' => []]);
        $this->execute($statement);

        $spans = $this->executionSpans($transaction);
        $this->assertSame(['db.system' => 'sqlite'], $spans[0]->getData());
        $this->assertSame('Alice', $spans[1]->getData()['db.query.parameter.:name']);
    }

    /**
     * @dataProvider disabledBindFailureProvider
     */
    public function testFailedBindDoesNotDiscardBindingRetainedBeforeTemporaryDisable(bool $throws): void
    {
        if (!$throws && self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 bindValue() has no failure return value.');
        }

        $options = new Options(['data_collection' => []]);
        $exception = new \RuntimeException('binding failed');
        $bindCalls = 0;
        $hub = $this->createHub($options);
        $driver = $this->createDriverStatement(true, 1, static function () use (&$bindCalls, $throws, $exception) {
            if (++$bindCalls > 1) {
                if ($throws) {
                    throw $exception;
                }

                return false;
            }

            return true;
        });
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':name', 'retained', ParameterType::STRING);
        $options->updateOptions(['data_collection' => null]);

        try {
            $this->assertFalse($this->bindValueWithResult($statement, ':name', 'not retained'));
        } catch (\RuntimeException $thrown) {
            $this->assertTrue($throws);
            $this->assertSame($exception, $thrown);
        }

        $options->updateOptions(['data_collection' => []]);
        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);
        $this->assertSame('retained', $this->executionSpans($transaction)[0]->getData()['db.query.parameter.:name']);
    }

    public function disabledBindFailureProvider(): \Generator
    {
        yield 'failed return' => [false];
        yield 'exception' => [true];
    }

    public function testBindingWhileDisabledIsNotCollectedAfterEnabling(): void
    {
        $options = new Options(['data_collection' => null]);
        $hub = $this->createHub($options);
        $driver = $this->createDriverStatement(true, 2);
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':name', 'disabled', ParameterType::STRING);
        $options->updateOptions(['data_collection' => []]);
        $transaction = $this->startTransaction($hub, true);

        $this->execute($statement);
        $statement->bindValue(':name', 'fresh', ParameterType::STRING);
        $this->execute($statement);

        $spans = $this->executionSpans($transaction);
        $this->assertSame(['db.system' => 'sqlite'], $spans[0]->getData());
        $this->assertSame('fresh', $spans[1]->getData()['db.query.parameter.:name']);
    }

    public function testMutableOptionsAreObservedBySubsequentOperations(): void
    {
        $options = new Options(['data_collection' => []]);
        $hub = $this->createHub($options);
        $driver = $this->createDriverStatement(true, 3);
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':name', 'old', ParameterType::STRING);
        $dataCollection = $options->getDataCollection();
        $this->assertNotNull($dataCollection);
        $dataCollection->setDatabaseQueryData(false);
        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);

        $dataCollection->setDatabaseQueryData(true);
        $this->execute($statement);
        $statement->bindValue(':name', 'fresh', ParameterType::STRING);
        $this->execute($statement);

        $spans = $this->executionSpans($transaction);
        $this->assertSame(['db.system' => 'sqlite'], $spans[0]->getData());
        $this->assertSame('old', $spans[1]->getData()['db.query.parameter.:name']);
        $this->assertSame('fresh', $spans[2]->getData()['db.query.parameter.:name']);
    }

    public function testStatementKeepsPolicyFromClientUsedAtConstruction(): void
    {
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true, 2);
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':name', 'old', ParameterType::STRING);
        $hub->bindClient($this->createClient(new Options(['data_collection' => null])));
        $transaction = $this->startTransaction($hub, true);
        $this->execute($statement);

        $hub->bindClient($this->createClient(new Options(['data_collection' => []])));
        $statement->bindValue(':name', 'fresh', ParameterType::STRING);
        $this->execute($statement);

        $spans = $this->executionSpans($transaction);
        $this->assertSame('old', $spans[0]->getData()['db.query.parameter.:name']);
        $this->assertSame('fresh', $spans[1]->getData()['db.query.parameter.:name']);
    }

    public function testExplicitEmptyParametersDoNotFallBackToBoundValues(): void
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 execute() does not accept parameters.');
        }

        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true);
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':name', 'bound', ParameterType::STRING);
        $transaction = $this->startTransaction($hub, true);

        $this->execute($statement, []);

        $this->assertSame(['db.system' => 'sqlite'], $this->executionSpans($transaction)[0]->getData());
    }

    /**
     * @dataProvider explicitParametersProvider
     *
     * @param array<array-key, mixed> $params
     */
    public function testExplicitParametersTakePrecedenceAndAreForwardedExactly(array $params): void
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 execute() does not accept parameters.');
        }

        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createMock(Statement::class);
        $driver->method('bindValue')->willReturn(true);
        $driver->expects($this->once())
            ->method('execute')
            ->with($this->identicalTo($params))
            ->willReturn(self::isDoctrineDBALVersion2Installed() ? true : $this->createMock(Result::class));
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':bound', 'bound', ParameterType::STRING);
        $transaction = $this->startTransaction($hub, true);

        $this->execute($statement, $params);

        $expected = ['db.system' => 'sqlite'];
        foreach ($params as $key => $value) {
            $expected['db.query.parameter.' . $key] = $value;
        }
        $this->assertSame($expected, $this->executionSpans($transaction)[0]->getData());
    }

    public function explicitParametersProvider(): \Generator
    {
        yield 'positional' => [[0 => 'zero', 3 => 'sparse']];
        yield 'named' => [[':name' => 'Alice']];
        yield 'empty' => [[]];
    }

    public function testNullExecutionParametersFallBackToBindingsAndAreForwarded(): void
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 execute() does not accept parameters.');
        }

        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createMock(Statement::class);
        $driver->method('bindValue')->willReturn(true);
        $driver->expects($this->once())
            ->method('execute')
            ->with(null)
            ->willReturn(self::isDoctrineDBALVersion2Installed() ? true : $this->createMock(Result::class));
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':name', 'bound', ParameterType::STRING);
        $transaction = $this->startTransaction($hub, true);

        $this->execute($statement, null);

        $this->assertSame('bound', $this->executionSpans($transaction)[0]->getData()['db.query.parameter.:name']);
    }

    public function testExplicitParametersInvalidateTrackedBindings(): void
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 execute() does not accept parameters.');
        }

        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true, 3);
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', ['db.system' => 'sqlite']);
        $statement->bindValue(':name', 'bound', ParameterType::STRING);
        $transaction = $this->startTransaction($hub, true);

        $this->execute($statement, [':name' => 'explicit']);
        $this->execute($statement, null);
        $statement->bindValue(':name', 'fresh', ParameterType::STRING);
        $this->execute($statement, null);

        $spans = $this->executionSpans($transaction);
        $this->assertSame('explicit', $spans[0]->getData()['db.query.parameter.:name']);
        $this->assertSame(['db.system' => 'sqlite'], $spans[1]->getData());
        $this->assertSame('fresh', $spans[2]->getData()['db.query.parameter.:name']);
    }

    /**
     * @dataProvider executionInvalidationProvider
     *
     * @param array<array-key, mixed> $params
     */
    public function testArrayExecutionInvalidatesBindingsInAllModes(string $mode, bool $throws, array $params): void
    {
        $options = new Options(['data_collection' => []]);
        $hub = $this->createHub($options);
        $statement = new ExistingStyleTracingStatement($hub, $this->createMock(Statement::class), 'SELECT :name', []);
        $statement->recordValueForTest(':name', 'bound');
        if ('no span' !== $mode) {
            $this->startTransaction($hub, 'unsampled' !== $mode);
        }
        if ('disabled' === $mode) {
            $options->updateOptions(['data_collection' => null]);
        }
        $exception = new \RuntimeException('execution failed');
        try {
            $statement->traceUsingExistingConvention(function ($actual) use ($params, $throws, $exception): void {
                $this->assertSame($params, $actual);
                if ($throws) {
                    throw $exception;
                }
            }, $params);
            $this->assertFalse($throws);
        } catch (\RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }

        $options->updateOptions(['data_collection' => []]);
        $transaction = $this->startTransaction($hub, true);
        $statement->traceUsingExistingConvention(static function (): void {}, null);
        $this->assertSame([], $this->executionSpans($transaction)[0]->getData());
    }

    public function executionInvalidationProvider(): \Generator
    {
        yield 'ordinary execution' => ['sampled', false, [':name' => 'explicit']];
        yield 'empty parameters' => ['sampled', false, []];
        yield 'driver exception' => ['sampled', true, [':name' => 'explicit']];
        yield 'unsampled' => ['unsampled', false, [':name' => 'explicit']];
        yield 'no span' => ['no span', false, [':name' => 'explicit']];
        yield 'disabled collection' => ['disabled', false, [':name' => 'explicit']];
        yield 'disabled and failed' => ['disabled', true, []];
    }

    /**
     * @dataProvider inactiveHubProvider
     */
    public function testNoClientOrActiveSpanDoesNotAffectDriverExecution(bool $hasClient): void
    {
        $driverResult = self::isDoctrineDBALVersion2Installed() ? true : $this->createMock(Result::class);
        $driver = $this->createMock(Statement::class);
        if (!self::isDoctrineDBALVersion4Installed()) {
            $driver->method('bindValue')->willReturn(true);
        }
        $driver->expects($this->once())->method('execute')->willReturn($driverResult);
        $hub = $hasClient ? $this->createHub(new Options(['data_collection' => []])) : new Hub();
        $statement = new TracingStatement($hub, $driver, 'SELECT ?', ['db.system' => 'sqlite']);
        $statement->bindValue(1, 'value', ParameterType::STRING);

        $this->assertSame($driverResult, $this->execute($statement));
    }

    public function inactiveHubProvider(): \Generator
    {
        yield 'no client' => [false];
        yield 'no active span' => [true];
    }

    public function testExistingSpanDataWinsOnAttributeCollisions(): void
    {
        $hub = $this->createHub(new Options(['data_collection' => []]));
        $driver = $this->createDriverStatement(true);
        $statement = new TracingStatement($hub, $driver, 'SELECT :name', [
            'db.system' => 'sqlite',
            'db.query.parameter.:name' => 'existing',
        ]);
        $statement->bindValue(':name', 'collected', ParameterType::STRING);
        $transaction = $this->startTransaction($hub, true);

        $this->execute($statement);

        $this->assertSame('existing', $this->executionSpans($transaction)[0]->getData()['db.query.parameter.:name']);
    }

    public function testExecutionExceptionsPreserveReferenceSnapshotAndFinishSpan(): void
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            $this->markTestSkipped('Doctrine DBAL 4 does not support bindParam().');
        }

        $hub = $this->createHub(new Options(['data_collection' => []]));
        $exception = new \RuntimeException('driver failure');
        $name = 'before execution';
        $value = ['nested' => ['name' => &$name]];
        $driver = $this->createMock(Statement::class);
        $this->configureBindParam($driver, static function (): bool {
            return true;
        });
        $driver->expects($this->once())->method('execute')->willReturnCallback(static function () use (&$name, $exception) {
            $name = 'changed by driver';

            throw $exception;
        });
        $statement = new TracingStatement($hub, $driver, 'SELECT ?', ['db.system' => 'sqlite']);
        $this->bindParam($statement, 1, $value);
        $transaction = $this->startTransaction($hub, true);

        try {
            $this->execute($statement);
            $this->fail('Execution should throw.');
        } catch (\RuntimeException $thrown) {
            $this->assertSame($exception, $thrown);
        }

        $this->assertSame('changed by driver', $name);
        $span = $this->executionSpans($transaction)[0];
        $this->assertSame(['nested' => ['name' => 'before execution']], $span->getData()['db.query.parameter.1']);
        $this->assertNotNull($span->getEndTimestamp());
    }

    /**
     * @param int|string $param
     * @param mixed      $value
     */
    private function bindValueWithResult(TracingStatement $statement, $param, $value): bool
    {
        /** @phpstan-ignore-next-line */
        return true === \call_user_func([$statement, 'bindValue'], $param, $value, ParameterType::STRING);
    }

    /**
     * @param int|string $param
     * @param mixed      $value
     */
    private function bindParam(TracingStatement $statement, $param, &$value): bool
    {
        $args = [$param, &$value, ParameterType::STRING];

        /** @phpstan-ignore-next-line */
        return true === \call_user_func_array([$statement, 'bindParam'], $args);
    }

    private function configureBindParam(Statement $driver, callable $callback): void
    {
        /** @phpstan-ignore-next-line */
        $driver->method('bindParam')->willReturnCallback($callback);
    }

    private function createHub(Options $options): Hub
    {
        return new Hub($this->createClient($options));
    }

    private function createClient(Options $options): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getOptions')->willReturn($options);

        return $client;
    }

    /**
     * @return Statement&MockObject
     */
    private function createDriverStatement(bool $successfulBind, int $executeCount = 1, ?callable $bindValueCallback = null): Statement
    {
        $driver = $this->createMock(Statement::class);
        if (null !== $bindValueCallback) {
            $driver->method('bindValue')->willReturnCallback($bindValueCallback);
        } elseif (!self::isDoctrineDBALVersion4Installed()) {
            $driver->method('bindValue')->willReturn($successfulBind);
        }

        if (self::isDoctrineDBALVersion2Installed()) {
            $driver->expects($this->exactly($executeCount))->method('execute')->willReturn(true);
        } else {
            $driver->expects($this->exactly($executeCount))->method('execute')->willReturn($this->createMock(Result::class));
        }

        return $driver;
    }

    private function startTransaction(Hub $hub, ?bool $sampled): Transaction
    {
        $transaction = new Transaction(TransactionContext::make()->setSampled($sampled), $hub);
        $transaction->initSpanRecorder();
        $hub->setSpan($transaction);

        return $transaction;
    }

    /**
     * @param mixed $params
     *
     * @return mixed
     */
    private function execute(TracingStatement $statement, $params = null)
    {
        if (self::isDoctrineDBALVersion4Installed()) {
            return $statement->execute();
        }

        return \call_user_func([$statement, 'execute'], $params);
    }

    /**
     * @return Span[]
     */
    private function executionSpans(Transaction $transaction): array
    {
        $recorder = $transaction->getSpanRecorder();
        $this->assertNotNull($recorder);

        return array_values(array_filter($recorder->getSpans(), static function (Span $span): bool {
            return TracingStatement::SPAN_OP_STMT_EXECUTE === $span->getOp();
        }));
    }
}

final class ExistingStyleTracingStatement extends AbstractTracingStatement
{
    /**
     * @param int|string $param
     * @param mixed      $value
     */
    public function recordValueForTest($param, $value): void
    {
        $this->recordBoundValue($param, $value);
    }

    /**
     * @param mixed ...$args
     *
     * @return mixed
     */
    public function traceUsingExistingConvention(callable $callback, ...$args)
    {
        return $this->traceFunction(SpanContext::make()->setOp(self::SPAN_OP_STMT_EXECUTE), $callback, ...$args);
    }
}
