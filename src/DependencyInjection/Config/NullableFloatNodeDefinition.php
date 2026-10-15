<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\DependencyInjection\Config;

use Symfony\Component\Config\Definition\Builder\FloatNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeParentInterface;
use Symfony\Component\Config\Definition\FloatNode;

/**
 * @internal
 *
 * @extends FloatNodeDefinition<NodeParentInterface|null>
 */
final class NullableFloatNodeDefinition extends FloatNodeDefinition
{
    protected function instantiateNode(): FloatNode
    {
        // @phpstan-ignore-next-line The parent is always a node when this is called, as in FloatNodeDefinition
        return new NullableFloatNode($this->name, $this->parent, $this->min, $this->max, $this->pathSeparator);
    }
}
