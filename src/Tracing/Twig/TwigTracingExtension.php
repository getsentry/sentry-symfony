<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tracing\Twig;

use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
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
     * @var \SplObjectStorage<object, Span> The currently active spans
     */
    private $spans;

    /**
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
        $this->spans = new \SplObjectStorage();
    }

    /**
     * This method is called before the execution of a block, a macro or a
     * template.
     *
     * @param Profile $profile The profiling data
     */
    public function enter(Profile $profile): void
    {
        $transaction = $this->hub->getTransaction();

        if (null === $transaction) {
            return;
        }

        $spanContext = new SpanContext();
        $spanContext->setOp('view.render');
        $spanContext->setDescription($this->getSpanDescription($profile));

        $this->spans[$profile] = $transaction->startChild($spanContext);
    }

    /**
     * This method is called when the execution of a block, a macro or a
     * template is finished.
     *
     * @param Profile $profile The profiling data
     */
    public function leave(Profile $profile): void
    {
        if (!isset($this->spans[$profile])) {
            return;
        }

        $this->spans[$profile]->finish();

        unset($this->spans[$profile]);
    }

    /**
     * {@inheritdoc}
     */
    public function getNodeVisitors(): array
    {
        return [
            new ProfilerNodeVisitor(self::class),
        ];
    }

    /**
     * Gets a short description for the span.
     *
     * @param Profile $profile The profiling data
     */
    private function getSpanDescription(Profile $profile): string
    {
        switch (true) {
            case $profile->isRoot():
                return $profile->getName();

            case $profile->isTemplate():
                return $profile->getTemplate();

            default:
                return \sprintf('%s::%s(%s)', $profile->getTemplate(), $profile->getType(), $profile->getName());
        }
    }
}
