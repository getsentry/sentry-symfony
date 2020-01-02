<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Jean85\PrettyVersions;
use PackageVersions\Versions;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\Configuration;
use Sentry\SentryBundle\Test\BaseTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

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
            'register_error_listener' => true,
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
                    '%kernel.project_dir%/vendor',
                ],
                'integrations' => $defaultSdkValues->getIntegrations(),
                'excluded_exceptions' => $defaultSdkValues->getExcludedExceptions(),
                'prefixes' => $defaultSdkValues->getPrefixes(),
                'project_root' => '%kernel.project_dir%',
                'tags' => [],
                'release' => Versions::getVersion('sentry/sentry-symfony'),
            ],
            'monolog' => [
                'error_handler' => [
                    'enabled' => false,
                    'level' => 'DEBUG',
                    'bubble' => true,
                ],
            ],
        ];

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

        $this->assertContains($input, $processed);
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
            $options[] = ['max_request_body_size', 'none'];
            $options[] = ['max_request_body_size', 'small'];
            $options[] = ['max_request_body_size', 'medium'];
            $options[] = ['max_request_body_size', 'always'];
        }

        if ($this->maxRequestBodySizeIsSupported()) {
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

    public function invalidValuesProvider(): array
    {
        $values = [
            ['attach_stacktrace', 'string'],
            ['before_breadcrumb', 'this is not a callable'],
            ['before_breadcrumb', [$this, 'is not a callable']],
            ['before_breadcrumb', false],
            ['before_breadcrumb', -1],
            ['before_send', 'this is not a callable'],
            ['before_send', [$this, 'is not a callable']],
            ['before_send', false],
            ['before_send', -1],
            ['context_lines', -1],
            ['context_lines', 99999],
            ['context_lines', 'string'],
            ['default_integrations', 'true'],
            ['default_integrations', 1],
            ['enable_compression', 'string'],
            ['environment', ''],
            ['error_types', []],
            ['excluded_exceptions', 'some-string'],
            ['http_proxy', []],
            ['in_app_exclude', 'some/single/path'],
            ['integrations', [1]],
            ['integrations', 'a string'],
            ['logger', []],
            ['max_breadcrumbs', -1],
            ['max_breadcrumbs', 'string'],
            ['max_value_length', -1],
            ['max_value_length', []],
            ['prefixes', 'string'],
            ['project_root', []],
            ['release', []],
            ['sample_rate', 1.1],
            ['sample_rate', -1],
            ['send_attempts', 1.5],
            ['send_attempts', 0],
            ['send_attempts', -1],
            ['send_default_pii', 'false'],
            ['server_name', []],
            ['tags', 'invalid-unmapped-tag'],
        ];

        if ($this->classSerializersAreSupported()) {
            $values[] = ['class_serializers', 'this is not a callable'];
            $values[] = ['class_serializers', [$this, 'is not a callable']];
            $values[] = ['class_serializers', false];
            $values[] = ['class_serializers', -1];
        }

        if ($this->maxRequestBodySizeIsSupported()) {
            $values[] = ['max_request_body_size', null];
            $values[] = ['max_request_body_size', 'invalid'];
        }

        return $values;
    }

    private function processConfiguration(array $values): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), ['sentry' => $values]);
    }
}
