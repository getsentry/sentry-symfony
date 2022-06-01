<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Twig;

use Sentry\State\HubInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SentryExtension extends AbstractExtension
{
    /**
     * @var HubInterface The current hub
     */
    private $hub;

    /**
     * @param HubInterface $hub The current hub
     */
    public function __construct(HubInterface $hub)
    {
        $this->hub = $hub;
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sentry_trace_meta', [$this, 'getTraceMeta'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Returns an HTML meta tag named `sentry-trace`.
     */
    public function getTraceMeta(): string
    {
        $span = $this->hub->getSpan();

        return sprintf('<meta name="sentry-trace" content="%s" />', null !== $span ? $span->toTraceparent() : '');
    }
}
