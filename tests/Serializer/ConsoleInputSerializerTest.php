<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\Serializer;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Serializer\ConsoleInputSerializer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

final class ConsoleInputSerializerTest extends TestCase
{
    public function testInvokeReturnsArgumentsAndOptions(): void
    {
        $definition = new InputDefinition([
            new InputArgument('name', InputArgument::REQUIRED),
            new InputOption('verbose', 'v', InputOption::VALUE_NONE),
        ]);

        $input = new ArrayInput(['name' => 'foo', '--verbose' => true], $definition);

        $serializer = new ConsoleInputSerializer();
        $result = $serializer($input);

        $this->assertSame(['name' => 'foo'], $result['arguments']);
        $this->assertArrayHasKey('verbose', $result['options']);
        $this->assertTrue($result['options']['verbose']);
    }
}
