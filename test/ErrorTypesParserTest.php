<?php

namespace Sentry\SentryBundle\Test;

use Sentry\SentryBundle\ErrorTypesParser;

class ErrorTypesParserTest extends \PHPUnit_Framework_TestCase
{
    public function test_error_types_parser()
    {
        $ex = new ErrorTypesParser('E_ALL & ~E_DEPRECATED & ~E_NOTICE');
        $this->assertEquals($ex->parse(), E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    }

    public function test_error_types_parser_throws_exception_for_unwanted_values()
    {
        $ex = new ErrorTypesParser('exec(something-dangerous)');

        $this->setExpectedException('\InvalidArgumentException');
        $ex->parse();
    }
}
