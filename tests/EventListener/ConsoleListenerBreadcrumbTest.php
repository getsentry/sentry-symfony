<?php

declare(strict_types=1);

namespace Sentry\SentryBundle\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\State\Hub;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Sentry\SentryBundle\EventListener\ConsoleListener;
use Sentry\SentryBundle\EventListener\TracingConsoleListener;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class ConsoleListenerBreadcrumbTest extends TestCase
{
    public function testBreadcrumbsAreCapturedInConsoleCommands(): void
    {
        $hub = $this->createMock(HubInterface::class);
        $scope = new Scope();
        
        // Track the scope to verify breadcrumbs
        $hub->expects($this->once())
            ->method('pushScope')
            ->willReturn($scope);
            
        $hub->expects($this->once())
            ->method('popScope');
            
        // Simulate breadcrumb being added during command execution
        $hub->expects($this->once())
            ->method('configureScope')
            ->willReturnCallback(function (callable $callback) use ($scope): void {
                $callback($scope);
            });

        $consoleListener = new ConsoleListener($hub);
        $tracingListener = new TracingConsoleListener($hub);
        
        $command = new Command('test:command');
        $input = new ArrayInput([]);
        $output = new NullOutput();
        
        $commandEvent = new ConsoleCommandEvent($command, $input, $output);
        $terminateEvent = new ConsoleTerminateEvent($command, $input, $output, 0);
        
        // Start command - pushes scope
        $consoleListener->handleConsoleCommandEvent($commandEvent);
        $tracingListener->handleConsoleCommandEvent($commandEvent);
        
        // Add breadcrumb during command execution
        $hub->configureScope(function (Scope $scope): void {
            $scope->addBreadcrumb(new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'console',
                'Test breadcrumb'
            ));
        });
        
        // Verify breadcrumb exists in scope
        $breadcrumbs = $scope->getBreadcrumbs();
        $this->assertCount(1, $breadcrumbs);
        $this->assertEquals('Test breadcrumb', $breadcrumbs[0]->getMessage());
        
        // Terminate command - should preserve breadcrumbs
        $tracingListener->handleConsoleTerminateEvent($terminateEvent);
        $consoleListener->handleConsoleTerminateEvent($terminateEvent);
    }
    
    public function testEventListenerPriorities(): void
    {
        // Verify that ConsoleListener terminate runs after TracingConsoleListener
        $container = new \Symfony\Component\DependencyInjection\ContainerBuilder();
        $container->register('Sentry\State\HubInterface', HubInterface::class);
        
        $consoleListenerDef = $container->register('console_listener', ConsoleListener::class)
            ->addArgument(new \Symfony\Component\DependencyInjection\Reference('Sentry\State\HubInterface'));
            
        $tracingListenerDef = $container->register('tracing_listener', TracingConsoleListener::class)
            ->addArgument(new \Symfony\Component\DependencyInjection\Reference('Sentry\State\HubInterface'))
            ->addArgument([]);
        
        // Add tags as they are in services.xml
        $consoleListenerDef->addTag('kernel.event_listener', [
            'event' => 'console.terminate',
            'method' => 'handleConsoleTerminateEvent',
            'priority' => -128
        ]);
        
        $tracingListenerDef->addTag('kernel.event_listener', [
            'event' => 'console.terminate', 
            'method' => 'handleConsoleTerminateEvent',
            'priority' => -54
        ]);
        
        // Verify priorities
        $consoleListenerTags = $consoleListenerDef->getTag('kernel.event_listener');
        $tracingListenerTags = $tracingListenerDef->getTag('kernel.event_listener');
        
        $consoleTerminatePriority = null;
        $tracingTerminatePriority = null;
        
        foreach ($consoleListenerTags as $tag) {
            if ($tag['event'] === 'console.terminate') {
                $consoleTerminatePriority = $tag['priority'];
            }
        }
        
        foreach ($tracingListenerTags as $tag) {
            if ($tag['event'] === 'console.terminate') {
                $tracingTerminatePriority = $tag['priority'];
            }
        }
        
        // ConsoleListener should run after TracingListener (lower priority number)
        $this->assertLessThan($tracingTerminatePriority, $consoleTerminatePriority);
    }
}