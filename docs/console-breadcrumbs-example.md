# Console Command Breadcrumbs Fix

## Issue Description

Prior to this fix, breadcrumbs added during console command execution were lost when the command completed successfully. This was due to the `ConsoleListener` popping the Sentry scope before the `TracingConsoleListener` had a chance to finish the transaction and capture the breadcrumbs.

## The Fix

The fix adjusts the event listener priority for `ConsoleListener::handleConsoleTerminateEvent` from `-64` to `-128`, ensuring it runs after `TracingConsoleListener::handleConsoleTerminateEvent` (priority `-54`).

## Example

Here's an example console command that demonstrates breadcrumb logging:

```php
<?php

namespace App\Command;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends Command
{
    protected static $defaultName = 'app:test';
    
    private LoggerInterface $logger;
    
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this->setDescription('Test command with breadcrumbs');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Starting test command...');
        
        // These log entries will be captured as breadcrumbs in Sentry
        $this->logger->info('Command started', ['step' => 'init']);
        
        // Simulate some work
        $this->logger->info('Processing data', ['items' => 100]);
        
        // More breadcrumbs
        $this->logger->warning('Slow operation detected', ['duration' => '5s']);
        
        $output->writeln('Command completed successfully!');
        
        return Command::SUCCESS;
    }
}
```

## Before the Fix

When running this command, the breadcrumbs (log entries) would be lost because:
1. `ConsoleListener` pushed a new scope when the command started
2. Breadcrumbs were added to this scope during execution
3. `ConsoleListener` popped the scope immediately on terminate (before tracing finished)
4. The breadcrumbs were discarded with the popped scope

## After the Fix

With the adjusted priorities:
1. `ConsoleListener` pushes a new scope when the command starts
2. Breadcrumbs are added to this scope during execution
3. `TracingConsoleListener` finishes the transaction/span (capturing breadcrumbs) at priority `-54`
4. `ConsoleListener` pops the scope at priority `-128` (after breadcrumbs are captured)
5. Breadcrumbs are successfully sent to Sentry with the transaction

## Configuration

No configuration changes are required. The fix is automatically applied through the service definition priorities.