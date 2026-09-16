<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\KernelWithSendDefaultPii;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @runTestsInSeparateProcesses
 */
final class ClientIpEnd2EndTest extends WebTestCase
{
    private const PROXY_IP = '10.0.0.1';
    private const CLIENT_IP = '1.2.3.4';

    protected static function getKernelClass(): string
    {
        return KernelWithSendDefaultPii::class;
    }

    protected function setUp(): void
    {
        StubTransport::$events = [];

        Request::setTrustedProxies([self::PROXY_IP], Request::HEADER_X_FORWARDED_FOR);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    /**
     * A 404 raised by the router is thrown at kernel.request priority 32. The user
     * context has to be filled before that, or RequestIntegration falls back to
     * $_SERVER['REMOTE_ADDR'] and attributes the event to the reverse proxy.
     */
    public function testRoutingNotFoundIsAttributedToTheClientBehindAProxy(): void
    {
        $this->request('/missing-page');

        $this->assertCapturedIpAddress(self::CLIENT_IP);
    }

    public function testControllerExceptionIsAttributedToTheClientBehindAProxy(): void
    {
        $this->request('/exception');

        $this->assertCapturedIpAddress(self::CLIENT_IP);
    }

    private function request(string $uri): void
    {
        $client = static::createClient(['debug' => false]);

        try {
            $client->request('GET', $uri, [], [], [
                'REMOTE_ADDR' => self::PROXY_IP,
                'HTTP_X_FORWARDED_FOR' => self::CLIENT_IP,
            ]);
        } catch (\Throwable $exception) {
            if (!$exception instanceof NotFoundHttpException) {
                throw $exception;
            }
        }
    }

    private function assertCapturedIpAddress(string $expectedIpAddress): void
    {
        $this->assertNotEmpty(StubTransport::$events, 'No event was captured');

        $user = StubTransport::$events[0]->getUser();

        $this->assertNotNull($user, 'The event carries no user context');
        $this->assertSame($expectedIpAddress, $user->getIpAddress());
    }
}
