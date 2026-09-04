<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\DataCollection;

use PHPUnit\Framework\TestCase;
use Sentry\Options;
use Sentry\SentryBundle\DataCollection\DataCollectionPolicy;

final class DataCollectionPolicyTest extends TestCase
{
    /**
     * @param array<string, mixed> $options
     *
     * @dataProvider shouldCollectUserInfoDataProvider
     */
    public function testShouldCollectUserInfo(array $options, bool $expected): void
    {
        $this->assertSame($expected, DataCollectionPolicy::shouldCollectUserInfo(new Options($options)));
    }

    /**
     * @return \Generator<string, array{array<string, mixed>, bool}>
     */
    public function shouldCollectUserInfoDataProvider(): \Generator
    {
        yield 'legacy behavior is disabled' => [
            ['send_default_pii' => false],
            false,
        ];

        yield 'legacy behavior is enabled' => [
            ['send_default_pii' => true],
            true,
        ];

        yield 'data collection disables user info regardless of legacy option' => [
            [
                'send_default_pii' => true,
                'data_collection' => ['user_info' => false],
            ],
            false,
        ];

        yield 'data collection enables user info regardless of legacy option' => [
            [
                'send_default_pii' => false,
                'data_collection' => ['user_info' => true],
            ],
            true,
        ];
    }
}
