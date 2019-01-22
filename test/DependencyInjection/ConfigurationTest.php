<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\SentryExtension;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class ConfigurationTest extends TestCase
{
    /**
     * @dataProvider optionValuesProvider
     */
    public function testOptionValues(string $option, $value): void
    {
        $this->getContainer(['options' => [$option => $value]]);

        $this->addToAssertionCount(1);
    }

    public function optionValuesProvider(): array
    {
        return [
            ['default_integrations', true],
            ['default_integrations', false],
            ['prefixes', ['some-string']],
            ['project_root', '/some/dir'],
            ['sample_rate', 0],
            ['sample_rate', 1],
            ['send_attempts', 1],
            ['send_attempts', 999],
            ['serialize_all_object', true],
            ['serialize_all_object', false],
        ];
    }

    /**
     * @dataProvider invalidValuesProvider
     */
    public function testInvalidValues(string $option, $value): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->getContainer(['options' => [$option => $value]]);
    }

    public function invalidValuesProvider(): array
    {
        return [
            ['default_integrations', 'true'],
            ['default_integrations', 1],
            ['prefixes', 'string'],
            ['project_root', []],
            ['sample_rate', 1.1],
            ['sample_rate', -1],
            ['send_attempts', 1.5],
            ['send_attempts', 0],
            ['send_attempts', -1],
            ['serialize_all_object', 'true'],
        ];
    }

    private function getContainer(array $configuration = []): Container
    {
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->setParameter('kernel.project_dir', '/dir/project/root');
        $containerBuilder->setParameter('kernel.environment', 'test');

        $extension = new SentryExtension();
        $extension->load(['sentry' => $configuration], $containerBuilder);

        $containerBuilder->compile();

        return $containerBuilder;
    }
}
