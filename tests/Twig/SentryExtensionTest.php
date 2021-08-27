<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Twig;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Twig\SentryExtension;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanId;
use Sentry\Tracing\TraceId;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SentryExtensionTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    public static function setUpBeforeClass(): void
    {
        if (!self::isTwigBundlePackageInstalled()) {
            self::markTestSkipped('This test requires the "symfony/twig-bundle" Composer package to be installed.');
        }
    }

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
    }

    /**
     * @dataProvider traceMetaFunctionDataProvider
     */
    public function testTraceMetaFunction(?Span $span, string $expectedTemplate): void
    {
        $this->hub->expects($this->once())
            ->method('getSpan')
            ->willReturn($span);

        $environment = new Environment(new ArrayLoader(['foo.twig' => '{{ sentry_trace_meta() }}']));
        $environment->addExtension(new SentryExtension($this->hub));

        $this->assertSame($expectedTemplate, $environment->render('foo.twig'));
    }

    /**
     * @return \Generator<mixed>
     */
    public function traceMetaFunctionDataProvider(): \Generator
    {
        yield [
            null,
            '<meta name="sentry-trace" content="" />',
        ];

        $transaction = new Transaction(new TransactionContext());
        $transaction->setTraceId(new TraceId('a3c01c41d7b94b90aee23edac90f4319'));
        $transaction->setSpanId(new SpanId('e69c2aef0ec34f2a'));

        yield [
            $transaction,
            '<meta name="sentry-trace" content="a3c01c41d7b94b90aee23edac90f4319-e69c2aef0ec34f2a" />',
        ];
    }

    private static function isTwigBundlePackageInstalled(): bool
    {
        return class_exists(TwigBundle::class);
    }
}
