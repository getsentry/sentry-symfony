<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use PHPUnit\Framework\TestCase;
use Sentry\Options;
use Sentry\SentryBundle\DependencyInjection\Configuration;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;

final class ConfigurationTest extends TestCase
{
    public function testProcessConfigurationWithDefaultConfiguration(): void
    {
        $expectedBundleDefaultConfig = [
            'register_error_listener' => true,
            'register_error_handler' => true,
            'logger' => null,
            'options' => [
                'integrations' => [],
                'prefixes' => array_merge(['%kernel.project_dir%'], array_filter(explode(\PATH_SEPARATOR, get_include_path() ?: ''))),
                'log_flush_threshold' => null,
                'enable_metrics' => true,
                'environment' => '%kernel.environment%',
                'release' => '%env(default::SENTRY_RELEASE)%',
                'ignore_exceptions' => [],
                'ignore_transactions' => [],
                'tags' => [],
                'in_app_exclude' => [
                    '%kernel.cache_dir%',
                    '%kernel.project_dir%/vendor',
                    '%kernel.build_dir%',
                ],
                'in_app_include' => [],
                'class_serializers' => [],
            ],
            'messenger' => [
                'enabled' => interface_exists(MessageBusInterface::class),
                'capture_soft_fails' => true,
                'isolate_breadcrumbs_by_message' => false,
                'isolate_context_by_message' => false,
            ],
            'tracing' => [
                'enabled' => true,
                'dbal' => [
                    'enabled' => class_exists(DoctrineBundle::class),
                    'ignore_prepare_spans' => false,
                    'connections' => [],
                ],
                'twig' => [
                    'enabled' => class_exists(TwigBundle::class),
                ],
                'cache' => [
                    'enabled' => class_exists(CacheItem::class),
                ],
                'http_client' => [
                    'enabled' => class_exists(HttpClient::class),
                ],
                'console' => [
                    'excluded_commands' => ['messenger:consume'],
                ],
            ],
        ];

        if (Kernel::VERSION_ID < 50200) {
            array_pop($expectedBundleDefaultConfig['options']['in_app_exclude']);
            $this->assertNotContains('%kernel.build_dir%', $expectedBundleDefaultConfig['options']['in_app_exclude'], 'Precondition failed, wrong default removed');
        }

        $this->assertSame($expectedBundleDefaultConfig, $this->processConfiguration([]));
    }

    /**
     * @param int|float $value
     *
     * @dataProvider sampleRateOptionDataProvider
     */
    public function testSampleRateOption($value): void
    {
        $config = $this->processConfiguration(['options' => ['sample_rate' => $value]]);

        $this->assertSame($value, $config['options']['sample_rate']);
    }

    /**
     * @return \Generator<mixed>
     */
    public function sampleRateOptionDataProvider(): \Generator
    {
        yield [0];
        yield [1];
        yield [0.0];
        yield [1.0];
        yield [0.01];
        yield [0.9];
    }

    /**
     * @param int|float $value
     *
     * @dataProvider sampleRateOptionWithInvalidValuesDataProvider
     */
    public function testSampleRateOptionWithInvalidValues($value, string $exceptionMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $this->processConfiguration(['options' => ['sample_rate' => $value]]);
    }

    /**
     * @return \Generator<mixed>
     */
    public function sampleRateOptionWithInvalidValuesDataProvider(): \Generator
    {
        yield [
            -1,
            'The value -1 is too small for path "sentry.options.sample_rate". Should be greater than or equal to 0',
        ];

        yield [
            2,
            'The value 2 is too big for path "sentry.options.sample_rate". Should be less than or equal to 1',
        ];

        yield [
            -0.1,
            'The value -0.1 is too small for path "sentry.options.sample_rate". Should be greater than or equal to 0',
        ];

        yield [
            1.01,
            'The value 1.01 is too big for path "sentry.options.sample_rate". Should be less than or equal to 1',
        ];
    }

    /**
     * @param int|float $value
     *
     * @dataProvider tracesSampleRateOptionDataProvider
     */
    public function testTracesSampleRateOption($value): void
    {
        $config = $this->processConfiguration(['options' => ['traces_sample_rate' => $value]]);

        $this->assertSame($value, $config['options']['traces_sample_rate']);
    }

    /**
     * @return \Generator<mixed>
     */
    public function tracesSampleRateOptionDataProvider(): \Generator
    {
        yield [0];
        yield [1];
        yield [0.0];
        yield [1.0];
        yield [0.01];
        yield [0.9];
    }

    /**
     * @param int|float $value
     *
     * @dataProvider tracesSampleRateOptionWithInvalidValuesDataProvider
     */
    public function testTracesSampleRateOptionWithInvalidValues($value, string $exceptionMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $this->processConfiguration(['options' => ['traces_sample_rate' => $value]]);
    }

    /**
     * @return \Generator<mixed>
     */
    public function tracesSampleRateOptionWithInvalidValuesDataProvider(): \Generator
    {
        yield [
            -1,
            'The value -1 is too small for path "sentry.options.traces_sample_rate". Should be greater than or equal to 0',
        ];

        yield [
            2,
            'The value 2 is too big for path "sentry.options.traces_sample_rate". Should be less than or equal to 1',
        ];

        yield [
            -0.1,
            'The value -0.1 is too small for path "sentry.options.traces_sample_rate". Should be greater than or equal to 0',
        ];

        yield [
            1.01,
            'The value 1.01 is too big for path "sentry.options.traces_sample_rate". Should be less than or equal to 1',
        ];
    }

    /**
     * @param int|float $value
     *
     * @dataProvider profilesSampleRateOptionDataProvider
     */
    public function testProfilesSampleRateOption($value): void
    {
        $config = $this->processConfiguration(['options' => ['profiles_sample_rate' => $value]]);

        $this->assertSame($value, $config['options']['profiles_sample_rate']);
    }

    /**
     * @return \Generator<mixed>
     */
    public function profilesSampleRateOptionDataProvider(): \Generator
    {
        yield [0];
        yield [1];
        yield [0.0];
        yield [1.0];
        yield [0.01];
        yield [0.9];
    }

    /**
     * @param int|float $value
     *
     * @dataProvider profilesSampleRateOptionWithInvalidValuesDataProvider
     */
    public function testProfilesSampleRateOptionWithInvalidValues($value, string $exceptionMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($exceptionMessage);

        $this->processConfiguration(['options' => ['profiles_sample_rate' => $value]]);
    }

    /**
     * @return \Generator<mixed>
     */
    public function profilesSampleRateOptionWithInvalidValuesDataProvider(): \Generator
    {
        yield [
            -1,
            'The value -1 is too small for path "sentry.options.profiles_sample_rate". Should be greater than or equal to 0',
        ];

        yield [
            2,
            'The value 2 is too big for path "sentry.options.profiles_sample_rate". Should be less than or equal to 1',
        ];

        yield [
            -0.1,
            'The value -0.1 is too small for path "sentry.options.profiles_sample_rate". Should be greater than or equal to 0',
        ];

        yield [
            1.01,
            'The value 1.01 is too big for path "sentry.options.profiles_sample_rate". Should be less than or equal to 1',
        ];
    }

    public function testOrgIdOption(): void
    {
        /** @var array{options: array{org_id: int}} $config */
        $config = $this->processConfiguration(['options' => ['org_id' => 1]]);

        $this->assertSame(1, $config['options']['org_id']);
    }

    public function testLogFlushThresholdOption(): void
    {
        /** @var array{options: array{log_flush_threshold: int}} $config */
        $config = $this->processConfiguration(['options' => ['log_flush_threshold' => 2]]);

        $this->assertSame(2, $config['options']['log_flush_threshold']);
    }

    public function testLogFlushThresholdOptionCanBeNull(): void
    {
        /** @var array{options: array{log_flush_threshold: null}} $config */
        $config = $this->processConfiguration(['options' => ['log_flush_threshold' => null]]);

        $this->assertNull($config['options']['log_flush_threshold']);
    }

    /**
     * @dataProvider strictTraceContinuationOptionDataProvider
     */
    public function testStrictTraceContinuationOption(bool $value): void
    {
        /** @var array{options: array{strict_trace_continuation: bool}} $config */
        $config = $this->processConfiguration(['options' => ['strict_trace_continuation' => $value]]);

        $this->assertSame($value, $config['options']['strict_trace_continuation']);
    }

    public function strictTraceContinuationOptionDataProvider(): \Generator
    {
        yield [true];
        yield [false];
    }

    /**
     * @dataProvider ignorePrepareSpansOptionDataProvider
     */
    public function testIgnorePrepareSpansOption(bool $value): void
    {
        /** @var array{tracing: array{dbal: array{ignore_prepare_spans: bool}}} $config */
        $config = $this->processConfiguration(['tracing' => ['dbal' => ['ignore_prepare_spans' => $value]]]);

        $this->assertSame($value, $config['tracing']['dbal']['ignore_prepare_spans']);
    }

    public function ignorePrepareSpansOptionDataProvider(): \Generator
    {
        yield [true];
        yield [false];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function processConfiguration(array $values): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), ['sentry' => $values]);
    }

    /**
     * @dataProvider collectionListOverrideProvider
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     */
    public function testCollectionListsAreReplacedAcrossConfigFiles(array $base, array $override): void
    {
        $processor = new Processor();
        /** @var array{options: array<string, mixed>} $merged */
        $merged = $processor->processConfiguration(new Configuration(), [
            ['options' => ['data_collection' => $base]],
            ['options' => ['data_collection' => $override]],
        ]);
        /** @var array{options: array<string, mixed>} $expected */
        $expected = $this->processConfiguration(['options' => ['data_collection' => $override]]);
        $this->assertSame($expected['options']['data_collection'], $merged['options']['data_collection']);
    }

    public function collectionListOverrideProvider(): \Generator
    {
        yield 'disable bodies' => [['http_bodies' => ['incomingRequest']], ['http_bodies' => []]];
        yield 'restrict bodies' => [['http_bodies' => ['incomingRequest', 'outgoingResponse']], ['http_bodies' => ['incomingRequest']]];
        foreach (['cookies', 'url_query_params', 'stack_frame_variables', 'http_headers'] as $key) {
            yield $key => [
                [$key => ['mode' => 'allowList', 'terms' => ['email']]],
                [$key => ['mode' => 'allowList', 'terms' => []]],
            ];
        }
        foreach (['request', 'response'] as $direction) {
            yield $direction . ' headers' => [
                ['http_headers' => [$direction => ['mode' => 'allowList', 'terms' => ['email']]]],
                ['http_headers' => [$direction => ['mode' => 'allowList', 'terms' => []]]],
            ];
        }
    }

    public function testDataCollectionOptionIsAbsentByDefault(): void
    {
        /** @var array{options: array<string, mixed>} $config */
        $config = $this->processConfiguration([]);

        $this->assertArrayNotHasKey('data_collection', $config['options']);
    }

    public function testEmptyDataCollectionOptionUsesCoreDefaults(): void
    {
        /** @var array{options: array<string, mixed>} $config */
        $config = $this->processConfiguration(['options' => ['data_collection' => []]]);
        $options = new Options($config['options']);
        $dataCollection = $options->getDataCollection();

        $this->assertNotNull($dataCollection);
        $this->assertSame([
            'incomingRequest',
            'outgoingRequest',
            'incomingResponse',
            'outgoingResponse',
        ], $dataCollection->getHttpBodies());
    }

    /**
     * @dataProvider stackFrameVariablesBooleanDataProvider
     */
    public function testDataCollectionNormalizesStackFrameVariablesBoolean(bool $value, string $expectedMode): void
    {
        /** @var array{options: array{data_collection: array{stack_frame_variables: array{mode: string}}}} $config */
        $config = $this->processConfiguration([
            'options' => [
                'data_collection' => [
                    'stack_frame_variables' => $value,
                ],
            ],
        ]);

        $this->assertSame($expectedMode, $config['options']['data_collection']['stack_frame_variables']['mode']);
    }

    public function stackFrameVariablesBooleanDataProvider(): \Generator
    {
        yield [true, 'denyList'];
        yield [false, 'off'];
    }

    /**
     * @dataProvider maxRequestBodySizeValuesDataProvider
     */
    public function testMaxRequestBodySizeValues(string $maxRequestBodySize): void
    {
        $options = new Options();
        $options->setMaxRequestBodySize($maxRequestBodySize);
        $this->assertSame($maxRequestBodySize, $options->getMaxRequestBodySize());
    }

    public function maxRequestBodySizeValuesDataProvider(): \Generator
    {
        yield ['never'];
        yield ['none'];
        yield ['small'];
        yield ['medium'];
        yield ['always'];
    }
}
