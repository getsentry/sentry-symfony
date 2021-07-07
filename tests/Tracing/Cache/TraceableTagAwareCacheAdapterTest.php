<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Cache;

use Sentry\SentryBundle\Tracing\Cache\TraceableTagAwareCacheAdapter;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

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

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertSame('cache.invalidate_tags', $spans[1]->getOp());
        $this->assertNotNull($spans[1]->getEndTimestamp());
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
