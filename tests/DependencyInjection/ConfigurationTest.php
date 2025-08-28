<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use PHPUnit\Framework\TestCase;
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
            'register_cron_monitor' => true,
            'logger' => null,
            'options' => [
                'integrations' => [],
                'prefixes' => array_merge(['%kernel.project_dir%'], array_filter(explode(\PATH_SEPARATOR, get_include_path() ?: ''))),
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
            ],
            'tracing' => [
                'enabled' => true,
                'dbal' => [
                    'enabled' => class_exists(DoctrineBundle::class),
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
