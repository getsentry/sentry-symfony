<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Twig;

use Sentry\State\HubInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

use function Sentry\getBaggage;
use function Sentry\getTraceparent;
use function Sentry\getW3CTraceparent;

final class SentryExtension extends AbstractExtension
{
    /**
     * @param HubInterface $hub The current hub
     */
    public function __construct(?HubInterface $hub = null)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sentry_trace_meta', [$this, 'getTraceMeta'], ['is_safe' => ['html']]),
            new TwigFunction('sentry_w3c_trace_meta', [$this, 'getW3CTraceMeta'], ['is_safe' => ['html']]),
            new TwigFunction('sentry_baggage_meta', [$this, 'getBaggageMeta'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Returns an HTML meta tag named `sentry-trace`.
     */
    public function getTraceMeta(): string
    {
        return \sprintf('<meta name="sentry-trace" content="%s" />', getTraceparent());
    }

    /**
     * Returns an HTML meta tag named `traceparent`.
     */
    public function getW3CTraceMeta(): string
    {
        return \sprintf('<meta name="traceparent" content="%s" />', getW3CTraceparent());
    }

    /**
     * Returns an HTML meta tag named `baggage`.
     */
    public function getBaggageMeta(): string
    {
        return \sprintf('<meta name="baggage" content="%s" />', getBaggage());
    }
}
