<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\End2End;

use Sentry\SentryBundle\Tests\End2End\App\KernelWithExtraConfig;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @runTestsInSeparateProcesses
 */
final class DataCollectionConsoleEnd2EndTest extends KernelTestCase
{
    /**
     * @param array{extra_config_files?: list<string>} $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new KernelWithExtraConfig($options['extra_config_files'] ?? []);
    }

    protected function setUp(): void
    {
        parent::setUp();

        StubTransport::$events = [];
    }

    /**
     * @param list<string> $extraConfigFiles
     * @param list<string> $argv
     *
     * @dataProvider fullCommandDataProvider
     */
    public function testFullCommandFiltersSensitiveValues(array $extraConfigFiles, array $argv, string $expectedErrorMessage, string $expectedFullCommand): void
    {
        $application = new Application(self::bootKernel(['extra_config_files' => $extraConfigFiles]));

        try {
            $application->doRun(new ArgvInput(array_merge(['bin/console', 'main-command'], $argv)), new NullOutput());
        } catch (\RuntimeException $exception) {
            $this->assertSame($expectedErrorMessage, $exception->getMessage());
        }

        $this->assertCount(1, StubTransport::$events);
        $this->assertSame(['Full command' => $expectedFullCommand], StubTransport::$events[0]->getExtra());
    }

    public function fullCommandDataProvider(): \Generator
    {
        yield 'The legacy options do not filter the command' => [
            [],
            ['--api-key=secret', '--option1', 'bar'],
            'This is an intentional error',
            'main-command --api-key=secret --option1 bar',
        ];

        yield 'The data collection options filter the values of sensitive options' => [
            [__DIR__ . '/App/config/data_collection/defaults.yml'],
            ['--api-key=secret', '--option1', 'bar'],
            'This is an intentional error',
            'main-command --api-key=[Filtered] --option1 bar',
        ];

        yield 'The data collection options filter the arguments and options of an invalid input' => [
            [__DIR__ . '/App/config/data_collection/defaults.yml'],
            ['--unknown', '--api-key=secret'],
            'The "--unknown" option does not exist.',
            'main-command [Filtered]',
        ];
    }
}
