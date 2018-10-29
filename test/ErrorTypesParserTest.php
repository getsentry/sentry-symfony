<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\ErrorTypesParser;

class ErrorTypesParserTest extends TestCase
{
    public function test_error_types_parser()
    {
        $ex = new ErrorTypesParser('E_ALL & ~E_DEPRECATED & ~E_NOTICE');
        $this->assertEquals($ex->parse(), E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    }

    public function test_error_types_parser_throws_exception_for_unwanted_values()
    {
        $ex = new ErrorTypesParser('exec(something-dangerous)');

        $this->expectException(\InvalidArgumentException::class);
        $ex->parse();
    }

    public function test_error_types_parser_throws_exception_for_unparsable_values()
    {
        $ex = new ErrorTypesParser('something-wrong');

        $this->expectException(\InvalidArgumentException::class);
        $ex->parse();
    }
}
