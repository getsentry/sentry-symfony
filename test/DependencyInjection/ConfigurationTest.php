<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Jean85\PrettyVersions;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\Configuration;
use Sentry\SentryBundle\Test\BaseTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpKernel\Kernel;

class ConfigurationTest extends BaseTestCase
{
    public function testDataProviderIsMappingTheRightNumberOfOptions(): void
    {
        $providerData = $this->optionValuesProvider();
        $supportedOptions = \array_unique(\array_column($providerData, 0));

        $expectedCount = $this->getSupportedOptionsCount();

        if (PrettyVersions::getVersion('sentry/sentry')->getPrettyVersion() !== '2.0.0') {
            ++$expectedCount;
        }

        $this->assertCount(
            $expectedCount,
            $supportedOptions,
            'Provider for configuration options mismatch: ' . PHP_EOL . print_r($supportedOptions, true)
        );
    }

    public function testInvalidDataProviderIsMappingTheRightNumberOfOptions(): void
    {
        $providerData = $this->invalidValuesProvider();
        $supportedOptions = \array_unique(\array_column($providerData, 0));

        $this->assertCount(
            $this->getSupportedOptionsCount(),
            $supportedOptions,
            'Provider for invalid configuration options mismatch: ' . PHP_EOL . print_r($supportedOptions, true)
        );
    }

    public function testConfigurationDefaults(): void
    {
        $defaultSdkValues = new Options();
        $processed = $this->processConfiguration([]);
        $expectedDefaults = [
            'dsn' => null,
            'listener_priorities' => [
                'request' => 1,
                'sub_request' => 1,
                'console' => 1,
                'request_error' => 128,
                'console_error' => 128,
            ],
            'options' => [
                'environment' => '%kernel.environment%',
                'in_app_exclude' => [
                    '%kernel.cache_dir%',
                    '%kernel.root_dir%/../vendor',
                ],
                'integrations' => $defaultSdkValues->getIntegrations(),
                'excluded_exceptions' => $defaultSdkValues->getExcludedExceptions(),
                'prefixes' => $defaultSdkValues->getPrefixes(),
                'project_root' => '%kernel.root_dir%/..',
                'tags' => [],
            ],
        ];

        if (method_exists(Kernel::class, 'getProjectDir')) {
            $expectedDefaults['options']['project_root'] = '%kernel.project_dir%';
            $expectedDefaults['options']['in_app_exclude'][1] = '%kernel.project_dir%/vendor';
        }

        if ($this->classSerializersAreSupported()) {
            $expectedDefaults['options']['class_serializers'] = [];
        }

        $this->assertEquals($expectedDefaults, $processed);
        $this->assertArrayNotHasKey('server_name', $processed['options'], 'server_name has to be fetched at runtime, not before (see #181)');
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
        $options = [
            ['attach_stacktrace', true],
            ['before_breadcrumb', 'count'],
            ['before_send', 'count'],
            ['context_lines', 4],
            ['context_lines', 99],
            ['default_integrations', true],
            ['default_integrations', false],
            ['enable_compression', false],
            ['environment', 'staging'],
            ['error_types', E_ALL],
            ['http_proxy', '1.2.3.4:5678'],
            ['in_app_exclude', ['some/path']],
            ['integrations', []],
            ['excluded_exceptions', [\Throwable::class]],
            ['logger', 'some-logger'],
            ['max_breadcrumbs', 15],
            ['max_value_length', 1000],
            ['prefixes', ['some-string']],
            ['project_root', '/some/dir'],
            ['release', 'abc0123'],
            ['sample_rate', 0],
            ['sample_rate', 1],
            ['send_attempts', 1],
            ['send_attempts', 999],
            ['send_default_pii', true],
            ['server_name', 'server001.example.com'],
            ['tags', ['tag-name' => 'value']],
        ];

        if (PrettyVersions::getVersion('sentry/sentry')->getPrettyVersion() !== '2.0.0') {
            $options[] = ['capture_silenced_errors', true];
        }

        if ($this->classSerializersAreSupported()) {
            $options[] = ['class_serializers', ['count']];
        }

        return $options;
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

    public function invalidValuesProvider(): \Generator
    {
        yield ['attach_stacktrace', 'string'];
        yield ['before_breadcrumb', 'this is not a callable'];
        yield ['before_breadcrumb', [$this, 'is not a callable']];
        yield ['before_breadcrumb', false];
        yield ['before_breadcrumb', -1];
        yield ['before_send', 'this is not a callable'];
        yield ['before_send', [$this, 'is not a callable']];
        yield ['before_send', false];
        yield ['before_send', -1];
        if ($this->classSerializersAreSupported()) {
            yield ['class_serializers', 'this is not a callable'];
            yield ['class_serializers', [$this, 'is not a callable']];
            yield ['class_serializers', false];
            yield ['class_serializers', -1];
        }
        yield ['context_lines', -1];
        yield ['context_lines', 99999];
        yield ['context_lines', 'string'];
        yield ['default_integrations', 'true'];
        yield ['default_integrations', 1];
        yield ['enable_compression', 'string'];
        yield ['environment', ''];
        yield ['error_types', []];
        yield ['excluded_exceptions', 'some-string'];
        yield ['http_proxy', []];
        yield ['in_app_exclude', 'some/single/path'];
        yield ['integrations', [1]];
        yield ['integrations', 'a string'];
        yield ['logger', []];
        yield ['max_breadcrumbs', -1];
        yield ['max_breadcrumbs', 'string'];
        yield ['max_value_length', -1];
        yield ['max_value_length', []];
        yield ['prefixes', 'string'];
        yield ['project_root', []];
        yield ['release', []];
        yield ['sample_rate', 1.1];
        yield ['sample_rate', -1];
        yield ['send_attempts', 1.5];
        yield ['send_attempts', 0];
        yield ['send_attempts', -1];
        yield ['send_default_pii', 'false'];
        yield ['server_name', []];
        yield ['tags', 'invalid-unmapped-tag'];
    }

    private function processConfiguration(array $values): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), ['sentry' => $values]);
    }
}
