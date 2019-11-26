<?php

namespace Sentry\SentryBundle\Test\End2End;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\Test\End2End\App\Kernel;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
        $client = static::createClient(['debug' => false]);

        try {
            $client->request('GET', '/missing-page');

            $response = $client->getResponse();

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(404, $response->getStatusCode());
        } catch (\Throwable $exception) {
            if (! $exception instanceof NotFoundHttpException) {
                throw $exception;
            }

            $this->assertSame('No route found for "GET /missing-page"', $exception->getMessage());
        }

        $this->assertLastEventIdIsNotNull($client);
    }

    public function testGet500(): void
    {
        $client = static::createClient();

        try {
            $client->request('GET', '/exception');

            $response = $client->getResponse();

            $this->assertInstanceOf(Response::class, $response);
            $this->assertSame(500, $response->getStatusCode());
            $this->assertContains('intentional error', $response->getContent());
        } catch (\Throwable $exception) {
            if (! $exception instanceof \RuntimeException) {
                throw $exception;
            }

            $this->assertSame('This is an intentional error', $exception->getMessage());
        }

        $this->assertLastEventIdIsNotNull($client);
    }

    private function assertLastEventIdIsNotNull(Client $client): void
    {
        $container = $client->getContainer();
        $this->assertNotNull($container);

        $hub = $container->get('test.hub');
        $this->assertInstanceOf(HubInterface::class, $hub);

        $this->assertNotNull($hub->getLastEventId(), 'Last error not captured');
    }
}
