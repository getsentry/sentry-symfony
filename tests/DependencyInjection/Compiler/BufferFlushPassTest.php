<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\Compiler;

use Monolog\Handler\BufferHandler;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\TestCase;
use Sentry\Monolog\Handler as SentryHandler;
use Sentry\SentryBundle\DependencyInjection\Compiler\BufferFlushPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class BufferFlushPassTest extends TestCase
{
    /**
     * @param Reference[] $services
     * @return string[]
     */
    private function servicesToName(array $services): array
    {
        return array_map(function ($item) {
            return (string) $item;
        }, $services);
    }

    /**
     * Tests that the flusher will only container references to handler that wrap sentry.
     *
     * @return void
     */
    public function testProcessWithMultipleHandlers()
    {
        $container = new ContainerBuilder();
        $container->setDefinition('sentry.handler', new Definition(SentryHandler::class));
        $container->setDefinition('null.handler', new Definition(NullHandler::class));
        $container->setDefinition('sentry.test.handler', new Definition(BufferHandler::class, [new Reference('sentry.handler')]));
        $container->setDefinition('other.test.handler', new Definition(BufferHandler::class, [new Reference('null.handler')]));

        (new BufferFlushPass())->process($container);
        $definition = $container->getDefinition('sentry.buffer_flusher');
        $serviceIds = $this->servicesToName($definition->getArgument(0));
        $this->assertEquals(['sentry.test.handler'], $serviceIds);
    }

    /**
     * Tests that if no sentry handlers exist, there is also no flusher.
     *
     * @return void
     */
    public function testProcessWithoutSentryHandler()
    {
        $container = new ContainerBuilder();
        $container->setDefinition('null.handler', new Definition(NullHandler::class));
        $container->setDefinition('other.test.handler', new Definition(BufferHandler::class, [new Reference('null.handler')]));

        (new BufferFlushPass())->process($container);
        $this->assertFalse($container->hasDefinition('sentry.buffer_flusher'));
    }

    /**
     * Tests that even if there are multiple sentry handler (for some reason), it will only
     * collect them and no others.
     *
     * @return void
     */
    public function testProcessWithMultipleSentryHandlers()
    {
        $container = new ContainerBuilder();
        $container->setDefinition('sentry.handler', new Definition(SentryHandler::class));
        $container->setDefinition('sentry.other.handler', new Definition(SentryHandler::class));
        $container->setDefinition('null.handler', new Definition(NullHandler::class));
        $container->setDefinition('sentry.test.handler', new Definition(BufferHandler::class, [new Reference('sentry.handler')]));
        $container->setDefinition('sentry.other.test.handler', new Definition(BufferHandler::class, [new Reference('sentry.other.handler')]));
        $container->setDefinition('other.test.handler', new Definition(BufferHandler::class, [new Reference('null.handler')]));

        (new BufferFlushPass())->process($container);
        $definition = $container->getDefinition('sentry.buffer_flusher');
        $serviceIds = $this->servicesToName($definition->getArgument(0));
        $this->assertEquals(['sentry.test.handler', 'sentry.other.test.handler'], $serviceIds);
    }

    /**
     * Tests that handlers that are named sentry will not be flushed because the matching happens by class
     * name and not by service id.
     *
     * @return void
     */
    public function testProcessWithFakeSentryHandlers()
    {
        $container = new ContainerBuilder();
        $container->setDefinition('sentry.handler', new Definition(SentryHandler::class));
        $container->setDefinition('sentry.fake.handler', new Definition(NullHandler::class));
        $container->setDefinition('sentry.test.handler', new Definition(BufferHandler::class, [new Reference('sentry.handler')]));
        $container->setDefinition('sentry.fake.test.handler', new Definition(BufferHandler::class, [new Reference('null.handler')]));

        (new BufferFlushPass())->process($container);
        $definition = $container->getDefinition('sentry.buffer_flusher');
        $serviceIds = $this->servicesToName($definition->getArgument(0));
        $this->assertEquals(['sentry.test.handler'], $serviceIds);
    }
}
