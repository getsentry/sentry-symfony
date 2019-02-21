<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\ErrorTypesParser;

class ErrorTypesParserTest extends TestCase
{
    /**
     * @dataProvider parsableValueProvider
     */
    public function testParse(string $value, int $expected): void
    {
        $ex = new ErrorTypesParser($value);
        $this->assertEquals($expected, $ex->parse());
    }

    public function parsableValueProvider(): array
    {
        return [
            ['E_ALL', E_ALL],
            ['E_ALL & ~E_DEPRECATED & ~E_NOTICE', E_ALL & ~E_DEPRECATED & ~E_NOTICE],
            ['-1', -1],
            [-1, -1],
        ];
    }

    public function testParseStopsAtDangerousValues(): void
    {
        $ex = new ErrorTypesParser('exec(something-dangerous)');

        $this->expectException(\InvalidArgumentException::class);
        $ex->parse();
    }

    public function testErrorTypesParserThrowsExceptionForUnparsableValues(): void
    {
        $ex = new ErrorTypesParser('something-wrong');

        $this->expectException(\InvalidArgumentException::class);
        $ex->parse();
    }
}
