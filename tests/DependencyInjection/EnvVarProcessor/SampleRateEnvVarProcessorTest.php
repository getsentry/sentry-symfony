<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DependencyInjection\EnvVarProcessor;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\DependencyInjection\EnvVarProcessor\SampleRateEnvVarProcessor;
use Symfony\Component\DependencyInjection\Exception\EnvNotFoundException;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

final class SampleRateEnvVarProcessorTest extends TestCase
{
    public function testGetProvidedTypes(): void
    {
        $this->assertSame([
            'sample_rate' => 'float',
            'sentry_sample_rate' => 'float',
        ], SampleRateEnvVarProcessor::getProvidedTypes());
    }

    /**
     * @param mixed $value
     *
     * @dataProvider getEnvDataProvider
     */
    public function testGetEnv($value, ?float $expectedSampleRate): void
    {
        $processor = new SampleRateEnvVarProcessor();

        $this->assertSame($expectedSampleRate, $processor->getEnv('sample_rate', 'SENTRY_TRACES_SAMPLE_RATE', static function () use ($value) {
            return $value;
        }));
    }

    /**
     * @return \Generator<mixed>
     */
    public function getEnvDataProvider(): \Generator
    {
        yield 'empty string' => ['', null];
        yield 'null' => [null, null];
        yield 'zero' => ['0', 0.0];
        yield 'float string' => ['0.25', 0.25];
        yield 'one' => ['1', 1.0];
        yield 'float' => [0.5, 0.5];
    }

    public function testGetEnvReturnsNullWhenEnvVarIsNotFound(): void
    {
        $processor = new SampleRateEnvVarProcessor();

        $this->assertNull($processor->getEnv('sample_rate', 'SENTRY_TRACES_SAMPLE_RATE', static function (): void {
            throw new EnvNotFoundException('Environment variable not found: "SENTRY_TRACES_SAMPLE_RATE".');
        }));
    }

    /**
     * @dataProvider getEnvThrowsOnInvalidValueDataProvider
     */
    public function testGetEnvThrowsOnInvalidValue(string $value, string $expectedExceptionMessage): void
    {
        $processor = new SampleRateEnvVarProcessor();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $processor->getEnv('sample_rate', 'SENTRY_TRACES_SAMPLE_RATE', static function () use ($value): string {
            return $value;
        });
    }

    /**
     * @return \Generator<mixed>
     */
    public function getEnvThrowsOnInvalidValueDataProvider(): \Generator
    {
        yield ['foo', 'Non-numeric env var "SENTRY_TRACES_SAMPLE_RATE" cannot be used as a sample rate.'];
        yield ['1.5', 'The env var "SENTRY_TRACES_SAMPLE_RATE" must be a sample rate between 0 and 1, but got 1.5.'];
        yield ['-0.1', 'The env var "SENTRY_TRACES_SAMPLE_RATE" must be a sample rate between 0 and 1, but got -0.1.'];
    }
}
