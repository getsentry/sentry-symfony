<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Twig;

use Sentry\State\HubInterface;
use Sentry\Tracing\SpanContext;
use SplObjectStorage;
use Twig\Extension\AbstractExtension;
use Twig\Profiler\NodeVisitor\ProfilerNodeVisitor;
use Twig\Profiler\Profile;

final class TwigTracingExtension extends AbstractExtension
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @var SplObjectStorage<object, \Sentry\Tracing\Span>
     */
    private $events;

    /**
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
        $this->events = new SplObjectStorage();
    }

    public function enter(Profile $profile): void
    {
        $transaction = $this->hub->getTransaction();

        if (null === $transaction || !$profile->isTemplate()) {
            return;
        }

        $spanContext = new SpanContext();
        $spanContext->setOp('twig.template');
        $spanContext->setDescription($profile->getName());

        $this->events[$profile] = $transaction->startChild($spanContext);
    }

    public function leave(Profile $profile): void
    {
        if (empty($this->events[$profile]) || !$profile->isTemplate()) {
            return;
        }

        $this->events[$profile]->finish();
        unset($this->events[$profile]);
    }

    public function getNodeVisitors(): array
    {
        return [new ProfilerNodeVisitor(static::class)];
    }
}
