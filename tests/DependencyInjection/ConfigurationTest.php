<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Jean85\PrettyVersions;
use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Messenger\MessageBusInterface;

final class ConfigurationTest extends TestCase
{
    public function testProcessConfigurationWithDefaultConfiguration(): void
    {
        $defaultPrefixes = array_filter(explode(\PATH_SEPARATOR, get_include_path() ?: ''));

        $expectedBundleDefaultConfig = [
            'register_error_listener' => true,
            'options' => [
                'integrations' => [],
                'prefixes' => array_merge(['%kernel.project_dir%'], $defaultPrefixes),
                'environment' => '%kernel.environment%',
                'release' => PrettyVersions::getRootPackageVersion()->getPrettyVersion(),
                'tags' => [],
                'in_app_exclude' => [
                    '%kernel.cache_dir%',
                    '%kernel.build_dir%',
                    '%kernel.project_dir%/vendor',
                ],
                'in_app_include' => [],
                'class_serializers' => [],
            ],
            'messenger' => [
                'enabled' => interface_exists(MessageBusInterface::class),
                'capture_soft_fails' => true,
            ],
            'tracing' => [
                'enabled' => true,
                'dbal' => [
                    'enabled' => false,
                    'connections' => class_exists(DoctrineBundle::class) ? ['%doctrine.default_connection%'] : [],
                ],
                'twig' => [
                    'enabled' => false,
                ],
            ],
        ];

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
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function processConfiguration(array $values): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), ['sentry' => $values]);
    }
}
