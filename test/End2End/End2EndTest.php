<?php

namespace Sentry\SentryBundle\Test\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Test\End2End\App\Kernel;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class_alias(TestCase::class, \PHPUnit_Framework_TestCase::class);

class End2EndTest extends WebTestCase
{
    protected function setUp(): void
    {
        static::$class = Kernel::class;

        parent::setUp();
    }

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testGet404(): void
    {
        $client = static::createClient();

        $client->request('GET', '/missing-page');

        $response = $client->getResponse();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(404, $response->getStatusCode(), $response->getContent());

        $hub = $client->getContainer()->get('test.hub');

        $this->assertInstanceOf(HubInterface::class, $hub);
        $this->assertNotNull($hub->getLastEventId(), 'Last error not captured');
    }
}
