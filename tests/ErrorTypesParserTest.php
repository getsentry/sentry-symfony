<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\ErrorTypesParser;

final class ErrorTypesParserTest extends TestCase
{
    /**
     * @dataProvider parseDataProvider
     */
    public function testParse(string $value, int $expectedValue): void
    {
        $this->assertSame($expectedValue, ErrorTypesParser::parse($value));
    }

    /**
     * @return \Generator<mixed>
     */
    public function parseDataProvider(): \Generator
    {
        yield [
            'E_ALL',
            \E_ALL,
        ];

        yield [
            'E_ERROR | E_WARNING | E_PARSE',
            \E_ERROR | \E_WARNING | \E_PARSE,
        ];

        yield [
            'E_ALL & ~E_DEPRECATED & ~E_NOTICE',
            \E_ALL & ~\E_DEPRECATED & ~\E_NOTICE,
        ];

        yield [
            'E_ALL & ~(E_DEPRECATED|E_NOTICE)',
            \E_ALL & ~(\E_DEPRECATED | \E_NOTICE),
        ];

        yield [
            '-1',
            -1,
        ];
    }

    /**
     * @dataProvider parseThrowsExceptionIfArgumentContainsInvalidCharactersDataProvider
     */
    public function testParseThrowsExceptionIfArgumentContainsInvalidCharacters(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ErrorTypesParser::parse($value);
    }

    /**
     * @return \Generator<mixed>
     */
    public function parseThrowsExceptionIfArgumentContainsInvalidCharactersDataProvider(): \Generator
    {
        yield ['foo'];
        yield ['-'];
        yield [' '];
        yield ['&'];
        yield ['|'];
        yield ['('];
        yield [')'];
        yield ['()'];
    }
}
