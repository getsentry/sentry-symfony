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
}
