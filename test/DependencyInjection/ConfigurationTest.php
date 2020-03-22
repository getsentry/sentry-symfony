<?php

namespace Sentry\SentryBundle\Test\DependencyInjection;

use Jean85\PrettyVersions;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\Configuration;
use Sentry\SentryBundle\Test\BaseTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Messenger\MessageBusInterface;

class ConfigurationTest extends BaseTestCase
{
    public function testDataProviderIsMappingTheRightNumberOfOptions(): void
    {
        $providerData = $this->optionValuesProvider();
        $supportedOptions = \array_unique(\array_column($providerData, 0));

        $expectedCount = $this->getSupportedOptionsCount() + 1;

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
                'worker_error' => 128,
            ],
            'options' => [
                'class_serializers' => [],
                'environment' => '%kernel.environment%',
                'in_app_include' => [],
                'in_app_exclude' => [
                    '%kernel.cache_dir%',
                    '%kernel.project_dir%/vendor',
                ],
                'integrations' => [],
                'excluded_exceptions' => [],
                'prefixes' => $defaultSdkValues->getPrefixes(),
                'tags' => [],
                'release' => PrettyVersions::getVersion('sentry/sentry-symfony')->getPrettyVersion(),
            ],
            'monolog' => [
                'error_handler' => [
                    'enabled' => false,
                    'level' => 'DEBUG',
                    'bubble' => true,
                ],
            ],
            'messenger' => [
                'enabled' => interface_exists(MessageBusInterface::class),
                'capture_soft_fails' => true,
            ],
        ];

        $this->assertEquals($expectedDefaults, $processed);
        $this->assertArrayNotHasKey('server_name', $processed['options'], 'server_name has to be fetched at runtime, not before (see #181)');
    }

    /**
     * @group legacy
     *
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
        return [
            ['attach_stacktrace', true],
            ['before_breadcrumb', 'count'],
            ['before_send', 'count'],
            ['capture_silenced_errors', true],
            ['class_serializers', ['count']],
            ['context_lines', 4],
            ['context_lines', 99],
            ['default_integrations', true],
            ['default_integrations', false],
            ['enable_compression', false],
            ['environment', 'staging'],
            ['error_types', E_ALL],
            ['http_proxy', '1.2.3.4:5678'],
            ['in_app_include', ['some/path']],
            ['in_app_exclude', ['some/path']],
            ['integrations', []],
            ['excluded_exceptions', [\Throwable::class]],
            ['logger', 'some-logger'],
            ['max_breadcrumbs', 15],
            ['max_request_body_size', 'none'],
            ['max_request_body_size', 'small'],
            ['max_request_body_size', 'medium'],
            ['max_request_body_size', 'always'],
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
            ['class_serializers', 'this is not a callable'],
            ['class_serializers', [$this, 'is not a callable']],
            ['class_serializers', false],
            ['class_serializers', -1],
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
            ['in_app_include', 'some/single/path'],
            ['in_app_exclude', 'some/single/path'],
            ['integrations', [1]],
            ['integrations', 'a string'],
            ['logger', []],
            ['max_breadcrumbs', -1],
            ['max_breadcrumbs', 'string'],
            ['max_request_body_size', null],
            ['max_request_body_size', 'invalid'],
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

        return $values;
    }

    private function processConfiguration(array $values): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), ['sentry' => $values]);
    }
}
