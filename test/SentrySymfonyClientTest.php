<?php

namespace Sentry\SentryBundle\Test;

use PHPUnit\Framework\TestCase;
use Sentry\SentryBundle\SentryBundle;
use Sentry\SentryBundle\SentrySymfonyClient;

class SentrySymfonyClientTest extends TestCase
{
    public function test_that_it_sets_sdk_name_and_version()
    {
        $client = new SentrySymfonyClient();

        $data = $client->get_default_data();

        $this->assertEquals('sentry-symfony', $data['sdk']['name']);
        $this->assertEquals(SentryBundle::getVersion(), $data['sdk']['version']);
    }

    public function test_that_it_forwards_options()
    {
        $client = new SentrySymfonyClient('https://a:b@app.getsentry.com/project', [
            'name' => 'test',
            'tags' => ['some_custom' => 'test']
        ]);

        $data = $client->get_default_data();

        // Not a big fan of doing this kind of assertions, couples tests to external API...
        // Perhaps, refactor is needed for this class?
        $this->assertEquals('test', $data['server_name']);
        $this->assertEquals(\Symfony\Component\HttpKernel\Kernel::VERSION, $data['tags']['symfony_version']);
        $this->assertEquals("undefined", $data['tags']['symfony_app_env']);
        $this->assertEquals("test", $data['tags']['some_custom']);
        $this->assertEquals('https://app.getsentry.com/api/project/store/', $client->getServerEndpoint(null));
        $this->assertEquals('a', $client->public_key);
        $this->assertEquals('b', $client->secret_key);
    }

    public function test_that_it_processes_error_types_option()
    {
        $client = new SentrySymfonyClient('https://a:b@app.getsentry.com/project', [
            'error_types' => 'E_ALL & ~E_WARNING',
        ]);

        $this->assertAttributeSame(E_ALL & ~E_WARNING, 'error_types', $client);
    }
}
