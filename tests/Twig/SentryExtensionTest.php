<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Twig;

use PHPUnit\Framework\TestCase;
use Sentry\Client;
use Sentry\ClientInterface;
use Sentry\Options;
use Sentry\SentryBundle\Twig\SentryExtension;
use Sentry\SentrySdk;
use Sentry\State\Hub;
use Sentry\State\Scope;
use Sentry\Tracing\PropagationContext;
use Sentry\Tracing\SpanId;
use Sentry\Tracing\TraceId;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Sentry\Transport\NullTransport;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SentryExtensionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!self::isTwigBundlePackageInstalled()) {
            self::markTestSkipped('This test requires the "symfony/twig-bundle" Composer package to be installed.');
        }
    }

    public function testTraceMetaFunctionWithNoActiveSpan(): void
    {
        $environment = new Environment(new ArrayLoader(['foo.twig' => '{{ sentry_trace_meta() }}']));
        $environment->addExtension(new SentryExtension());

        $propagationContext = PropagationContext::fromDefaults();
        $propagationContext->setTraceId(new TraceId('566e3688a61d4bc888951642d6f14a19'));
        $propagationContext->setSpanId(new SpanId('566e3688a61d4bc8'));

        $hub = new Hub(null, new Scope($propagationContext));

        SentrySdk::setCurrentHub($hub);

        $this->assertSame('<meta name="sentry-trace" content="566e3688a61d4bc888951642d6f14a19-566e3688a61d4bc8" />', $environment->render('foo.twig'));
    }

    public function testTraceMetaFunctionWithActiveSpan(): void
    {
        $environment = new Environment(new ArrayLoader(['foo.twig' => '{{ sentry_trace_meta() }}']));
        $environment->addExtension(new SentryExtension());

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->atLeastOnce())
            ->method('getOptions')
            ->willReturn(new Options([
                'traces_sample_rate' => 1.0,
                'release' => '1.0.0',
                'environment' => 'development',
            ]));

        $hub = new Hub($client);

        SentrySdk::setCurrentHub($hub);

        $transaction = new Transaction(new TransactionContext());
        $transaction->setTraceId(new TraceId('a3c01c41d7b94b90aee23edac90f4319'));
        $transaction->setSpanId(new SpanId('e69c2aef0ec34f2a'));

        $hub->setSpan($transaction);

        $this->assertSame('<meta name="sentry-trace" content="a3c01c41d7b94b90aee23edac90f4319-e69c2aef0ec34f2a" />', $environment->render('foo.twig'));
    }

    public function testBaggageMetaFunctionWithNoActiveSpan(): void
    {
        $environment = new Environment(new ArrayLoader(['foo.twig' => '{{ sentry_baggage_meta() }}']));
        $environment->addExtension(new SentryExtension());

        $propagationContext = PropagationContext::fromDefaults();
        $propagationContext->setTraceId(new TraceId('566e3688a61d4bc888951642d6f14a19'));

        $client = $this->createMock(ClientInterface::class);
        $client->expects($this->atLeastOnce())
            ->method('getOptions')
            ->willReturn(new Options([
                'traces_sample_rate' => 1.0,
                'release' => '1.0.0',
                'environment' => 'development',
            ]));

        $hub = new Hub($client, new Scope($propagationContext));

        SentrySdk::setCurrentHub($hub);

        $this->assertSame('<meta name="baggage" content="sentry-trace_id=566e3688a61d4bc888951642d6f14a19,sentry-sample_rate=1,sentry-release=1.0.0,sentry-environment=development" />', $environment->render('foo.twig'));
    }

    public function testBaggageMetaFunctionWithActiveSpan(): void
    {
        $environment = new Environment(new ArrayLoader(['foo.twig' => '{{ sentry_baggage_meta() }}']));
        $environment->addExtension(new SentryExtension());

        $client = new Client(new Options([
            'traces_sample_rate' => 1.0,
            'release' => '1.0.0',
            'environment' => 'development',
        ]), new NullTransport());

        $hub = new Hub($client);

        SentrySdk::setCurrentHub($hub);

        $transaction = new Transaction(new TransactionContext());
        $transaction->setTraceId(new TraceId('a3c01c41d7b94b90aee23edac90f4319'));

        $hub->setSpan($transaction);

        $this->assertSame('<meta name="baggage" content="sentry-trace_id=a3c01c41d7b94b90aee23edac90f4319,sentry-transaction=%3Cunlabeled%20transaction%3E,sentry-release=1.0.0,sentry-environment=development" />', $environment->render('foo.twig'));
    }

    private static function isTwigBundlePackageInstalled(): bool
    {
        return class_exists(TwigBundle::class);
    }
}
