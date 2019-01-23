<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpKernel\Kernel;

class ConfigurationTest extends TestCase
{
    public function testConfigurationDefaults(): void
    {
        $defaultSdkValues = new Options();
        $processed = $this->processConfiguration([]);
        $expectedDefaults = [
            'dsn' => null,
            'listener_priorities' => [
                'request' => 1,
                'console' => 1,
            ],
            'options' => [
                'excluded_exceptions' => $defaultSdkValues->getExcludedExceptions(),
                'prefixes' => $defaultSdkValues->getPrefixes(),
                'project_root' => '%kernel.root_dir%/..',
            ],
        ];

        if (method_exists(Kernel::class, 'getProjectDir')) {
            $expectedDefaults['options']['project_root'] = '%kernel.project_dir%';
        }

        $this->assertEquals($expectedDefaults, $processed);
    }

    /**
     * @dataProvider optionValuesProvider
     */
    public function testOptionValuesProcessing(string $option, $value): void
    {
        $input = ['options' => [$option => $value]];
        $processed = $this->processConfiguration($input);

        $this->assertArraySubset($input, $processed);
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
        ];
    }

    /**
     * @dataProvider invalidValuesProvider
     */
    public function testInvalidValues(string $option, $value): void
    {
        $input = ['options' => [$option => $value]];

        $this->expectException(InvalidConfigurationException::class);

        $this->processConfiguration($input);
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
        ];
    }

    private function processConfiguration(array $values): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), ['sentry' => $values]);
    }
}
