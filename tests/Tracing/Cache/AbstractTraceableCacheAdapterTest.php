<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Cache;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Cache\ResettableInterface;
use Symfony\Contracts\Cache\CacheInterface as BaseCacheInterface;

/**
 * @phpstan-template TCacheAdapter of AdapterInterface
 */
abstract class AbstractTraceableCacheAdapterTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    protected $hub;

    public static function setUpBeforeClass(): void
    {
        if (!self::isCachePackageInstalled()) {
            self::markTestSkipped();
        }
    }

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
    }

    public function testGetItem(): void
    {
        $cacheItem = new CacheItem();
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('getItem')
            ->with('foo')
            ->willReturn($cacheItem);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertSame($cacheItem, $adapter->getItem('foo'));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.get_item', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testGetItems(): void
    {
        $cacheItems = [new CacheItem()];
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('getItems')
            ->with(['foo'])
            ->willReturn($cacheItems);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertSame($cacheItems, $adapter->getItems(['foo']));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.get_items', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testClear(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('clear')
            ->with('foo')
            ->willReturn(true);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->clear('foo'));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.clear', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testGet(): void
    {
        $callback = static function () {};
        $metadata = [];
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(CacheInterface::class);
        $decoratedAdapter->expects($this->once())
            ->method('get')
            ->with('foo', $callback, 1.0, $metadata)
            ->willReturn('bar');

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertSame('bar', $adapter->get('foo', $callback, 1.0, $metadata));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.get_item', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testGetThrowsExceptionIfDecoratedAdapterDoesNotImplementTheCacheInterface(): void
    {
        $adapter = $this->createCacheAdapter($this->createMock(static::getAdapterClassFqcn()));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage(sprintf('The %s::get() method is not supported because the decorated adapter does not implement the "Symfony\\Contracts\\Cache\\CacheInterface" interface.', \get_class($adapter)));

        $adapter->get('foo', static function () {});
    }

    public function testDelete(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(CacheInterface::class);
        $decoratedAdapter->expects($this->once())
            ->method('delete')
            ->with('foo')
            ->willReturn(true);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->delete('foo'));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.delete_item', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testDeleteThrowsExceptionIfDecoratedAdapterDoesNotImplementTheCacheInterface(): void
    {
        $adapter = $this->createCacheAdapter($this->createMock(static::getAdapterClassFqcn()));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage(sprintf('The %s::delete() method is not supported because the decorated adapter does not implement the "Symfony\\Contracts\\Cache\\CacheInterface" interface.', \get_class($adapter)));

        $adapter->delete('foo');
    }

    public function testHasItem(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('hasItem')
            ->with('foo')
            ->willReturn(true);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->hasItem('foo'));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.has_item', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testDeleteItem(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('deleteItem')
            ->with('foo')
            ->willReturn(true);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->deleteItem('foo'));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.delete_item', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testDeleteItems(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('deleteItems')
            ->with(['foo'])
            ->willReturn(true);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->deleteItems(['foo']));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.delete_items', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testSave(): void
    {
        $cacheItem = new CacheItem();
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('save')
            ->with($cacheItem)
            ->willReturn(true);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->save($cacheItem));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.save', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testSaveDeferred(): void
    {
        $cacheItem = new CacheItem();
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('saveDeferred')
            ->with($cacheItem)
            ->willReturn(true);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->saveDeferred($cacheItem));

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.save_deferred', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testCommit(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(static::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('commit')
            ->willReturn(true);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->commit());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.commit', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testPrune(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $decoratedAdapter = $this->createMock(PruneableCacheAdapterInterface::class);
        $decoratedAdapter->expects($this->once())
            ->method('prune')
            ->willReturn(true);

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->prune());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.prune', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testPruneThrowsExceptionIfDecoratedAdapterIsNotPruneable(): void
    {
        $adapter = $this->createCacheAdapter($this->createMock(static::getAdapterClassFqcn()));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage(sprintf('The %s::prune() method is not supported because the decorated adapter does not implement the "Symfony\\Component\\Cache\\PruneableInterface" interface.', \get_class($adapter)));

        $adapter->prune();
    }

    public function testReset(): void
    {
        $decoratedAdapter = $this->createMock(ResettableCacheAdapterInterface::class);
        $decoratedAdapter->expects($this->once())
            ->method('reset');

        $adapter = $this->createCacheAdapter($decoratedAdapter);
        $adapter->reset();
    }

    public function testResetThrowsExceptionIfDecoratedAdapterIsNotResettable(): void
    {
        $adapter = $this->createCacheAdapter($this->createMock(static::getAdapterClassFqcn()));

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage(sprintf('The %s::reset() method is not supported because the decorated adapter does not implement the "Symfony\\Component\\Cache\\ResettableInterface" interface.', \get_class($adapter)));

        $adapter->reset();
    }

    private static function isCachePackageInstalled(): bool
    {
        return interface_exists(BaseCacheInterface::class);
    }

    /**
     * @phpstan-return TCacheAdapter
     */
    abstract protected function createCacheAdapter(AdapterInterface $decoratedAdapter): AdapterInterface;

    /**
     * @return class-string<AdapterInterface>
     */
    abstract protected static function getAdapterClassFqcn(): string;
}

interface ResettableCacheAdapterInterface extends ResettableInterface, TagAwareAdapterInterface
{
}

interface PruneableCacheAdapterInterface extends PruneableInterface, TagAwareAdapterInterface
{
}

interface CacheInterface extends BaseCacheInterface, TagAwareAdapterInterface
{
}
