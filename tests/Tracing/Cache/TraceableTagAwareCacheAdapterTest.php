<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Cache;

use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapter;
use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapterForV3WithNamespace;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\NamespacedPoolInterface;

/**
 * @phpstan-extends AbstractTraceableCacheAdapterTest<TraceableTagAwareCacheAdapter, TagAwareAdapterInterface>
 */
final class TraceableTagAwareCacheAdapterTest extends AbstractTraceableCacheAdapterTest
{
    public function testInvalidateTags(): void
    {
        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = $this->createMock(self::getAdapterClassFqcn());
        $decoratedAdapter->expects($this->once())
            ->method('invalidateTags')
            ->with(['foo'])
            ->willReturn(true);

        $adapter = $this->createCacheAdapter($decoratedAdapter);

        $this->assertTrue($adapter->invalidateTags(['foo']));
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.invalidate_tags', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testWithSubNamespaceThrowsWhenNotNamespaced(): void
    {
        if (!interface_exists(NamespacedPoolInterface::class)) {
            $this->markTestSkipped('Namespaced caches are not supported by this Symfony version.');
        }

        $decoratedAdapter = $this->createMock(TagAwareAdapterInterface::class);
        $adapter = new TraceableTagAwareCacheAdapterForV3WithNamespace($this->hub, $decoratedAdapter);

        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('withSubNamespace() method is not supported');

        $adapter->withSubNamespace('foo');
    }

    public function testWithSubNamespaceReturnsNamespacedAdapter(): void
    {
        if (!interface_exists(NamespacedPoolInterface::class)) {
            $this->markTestSkipped('Namespaced caches are not supported by this Symfony version.');
        }

        $decoratedAdapter = new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter());
        if (!method_exists($decoratedAdapter, 'withSubNamespace')) {
            $this->markTestSkipped('TagAwareAdapter::withSubNamespace() is not available in this Symfony version.');
        }
        $namespacedAdapter = $decoratedAdapter->withSubNamespace('foo');

        $adapter = new TraceableTagAwareCacheAdapterForV3WithNamespace($this->hub, $decoratedAdapter);

        $result = $adapter->withSubNamespace('foo');

        $this->assertInstanceOf(NamespacedPoolInterface::class, $result);
        $this->assertNotSame($adapter, $result);

        $ref = new \ReflectionProperty($result, 'decoratedAdapter');
        $ref->setAccessible(true);

        $this->assertEquals($namespacedAdapter, $ref->getValue($result));
    }

    public function testNamespaceIsAddedToSpanData(): void
    {
        if (!interface_exists(NamespacedPoolInterface::class)) {
            $this->markTestSkipped('Namespaced caches are not supported by this Symfony version.');
        }

        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->any())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter());
        if (!method_exists($decoratedAdapter, 'withSubNamespace')) {
            $this->markTestSkipped('TagAwareAdapter::withSubNamespace() is not available in this Symfony version.');
        }
        $adapter = new TraceableTagAwareCacheAdapterForV3WithNamespace($this->hub, $decoratedAdapter);

        $namespaced = $adapter->withSubNamespace('foo')->withSubNamespace('bar');

        $namespaced->delete('example');
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.remove', $spans[1]->getOp());
        $this->assertSame('foo.bar', $spans[1]->getData()['cache.namespace']);
    }

    public function testSingleNamespaceIsAddedToSpanData(): void
    {
        if (!interface_exists(NamespacedPoolInterface::class)) {
            $this->markTestSkipped('Namespaced caches are not supported by this Symfony version.');
        }

        $transaction = new Transaction(new TransactionContext(), $this->hub);
        $transaction->initSpanRecorder();

        $this->hub->expects($this->any())
            ->method('getSpan')
            ->willReturn($transaction);

        $decoratedAdapter = new TagAwareAdapter(new ArrayAdapter(), new ArrayAdapter());
        if (!method_exists($decoratedAdapter, 'withSubNamespace')) {
            $this->markTestSkipped('TagAwareAdapter::withSubNamespace() is not available in this Symfony version.');
        }
        $adapter = new TraceableTagAwareCacheAdapterForV3WithNamespace($this->hub, $decoratedAdapter);

        $namespaced = $adapter->withSubNamespace('foo');

        $namespaced->delete('example');
        $this->assertNotNull($transaction->getSpanRecorder());

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.remove', $spans[1]->getOp());
        $this->assertSame('foo', $spans[1]->getData()['cache.namespace']);
    }

    /**
     * {@inheritdoc}
     */
    protected function createCacheAdapter(AdapterInterface $decoratedAdapter): TraceableTagAwareCacheAdapter
    {
        return new TraceableTagAwareCacheAdapter($this->hub, $decoratedAdapter);
    }

    /**
     * {@inheritdoc}
     */
    protected static function getAdapterClassFqcn(): string
    {
        return TagAwareAdapterInterface::class;
    }
}
