<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Tracing\Twig;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Tracing\Twig\TwigTracingExtension;
use Sentry\State\HubInterface;
use Sentry\Tracing\Transaction;
use Sentry\Tracing\TransactionContext;
use Twig\Profiler\Profile;

final class TwigTracingExtensionTest extends TestCase
{
    /**
     * @var MockObject&HubInterface
     */
    private $hub;

    /**
     * @var TwigTracingExtension
     */
    private $listener;

    protected function setUp(): void
    {
        $this->hub = $this->createMock(HubInterface::class);
        $this->listener = new TwigTracingExtension($this->hub);
    }

    /**
     * @dataProvider enterDataProvider
     */
    public function testEnter(Profile $profile, string $spanDescription): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $this->listener->enter($profile);

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNull($spans[1]->getEndTimestamp());
        $this->assertSame('view.render', $spans[1]->getOp());
        $this->assertSame($spanDescription, $spans[1]->getDescription());
    }

    /**
     * @return \Generator<mixed>
     */
    public function enterDataProvider(): \Generator
    {
        yield [
            new Profile('main.twig', Profile::ROOT),
            'main',
        ];

        yield [
            new Profile('main.twig', Profile::TEMPLATE),
            'main.twig',
        ];

        yield [
            new Profile('main.twig', Profile::BLOCK, 'content'),
            'main.twig::block(content)',
        ];

        yield [
            new Profile('main.twig', Profile::MACRO, 'input'),
            'main.twig::macro(input)',
        ];
    }

    public function testEnterDoesNothingIfNoSpanIsSetOnHub(): void
    {
        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn(null);

        $this->listener->enter(new Profile('main', Profile::TEMPLATE));
    }

    public function testLeave(): void
    {
        $transaction = new Transaction(new TransactionContext());
        $transaction->initSpanRecorder();

        $this->hub->expects($this->once())
            ->method('getTransaction')
            ->willReturn($transaction);

        $profile = new Profile('main', Profile::TEMPLATE);

        $this->listener->enter($profile);
        $this->listener->leave($profile);

        $spans = $transaction->getSpanRecorder()->getSpans();

        $this->assertCount(2, $spans);
        $this->assertNotNull($spans[1]->getEndTimestamp());
    }

    public function testLeaveDoesNothingIfSpanDoesNotExistsForProfile(): void
    {
        $this->expectNotToPerformAssertions();

        $this->listener->leave(new Profile('main', Profile::TEMPLATE));
    }
}
