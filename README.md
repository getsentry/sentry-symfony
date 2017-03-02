# sentry-symfony

Symfony integration for [Sentry](https://getsentry.com/).

[![Build Status](https://travis-ci.org/getsentry/sentry-symfony.svg?branch=master)](https://travis-ci.org/getsentry/sentry-symfony)

## Installation

### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```bash
$ composer require sentry/sentry-symfony
```

This command requires you to have Composer installed globally, as explained
in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles
in the `app/AppKernel.php` file of your project:

```php
<?php
// app/AppKernel.php

// ...
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            // ...

            new Sentry\SentryBundle\SentryBundle(),
        );

        // ...
    }

    // ...
}
```

### Step 3: Configure the SDK

Add your DSN to ``app/config/config.yml``:

```yaml

sentry:
    dsn: "https://public:secret@sentry.example.com/1"
```

## Configuration

The following can be configured via ``app/config/config.yml``:

### app_path

The base path to your application. Used to trim prefixes and mark frames as part of your application.

```yaml
sentry:
    app_path: "/path/to/myapp"
```

### dsn

```yaml
sentry:
    dsn: "https://public:secret@sentry.example.com/1"
```

### environment

The environment your code is running in (e.g. production).

```yaml
sentry:
    environment: "%kernel.environment%"
```

### release

The version of your application. Often this is the git sha.

```yaml
sentry:
    release: "beeee2a06521a60e646bbb8fe38702e61e4929bf"
```

### prefixes

A list of prefixes to strip from filenames. Often these would be vendor/include paths.

```yaml
sentry:
    prefixes:
        - /usr/lib/include
```

### skip some exceptions

```yaml
sentry:
    skip_capture:
        - "Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface"
```

### error types

Define which error types should be reported.

```yaml
sentry:
    error_types: E_ALL & ~E_DEPRECATED & ~E_NOTICE
```

## Customization

It is possible to customize the configuration of the user context, as well
as modify the client immediately before an exception is captured by wiring
up an event subscriber to the events that are emitted by the default
configured `ExceptionListener` (alternatively, you can also just defined
your own custom exception listener).

### Create a Custom ExceptionListener

You can always replace the default `ExceptionListener` with your own custom
listener. To do this, assign a different class to the `exception_listener`
property in your Sentry configuration, e.g.:

```yaml
sentry:
    exception_listener: AppBundle\EventListener\MySentryExceptionListener
```

... and then define the custom `ExceptionListener`, e.g.:

```php
// src/AppBundle/EventSubscriber/MySentryEventListener.php
namespace AppBundle\EventSubscriber;

use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class MySentryExceptionListener
{
    // ...

    public function __construct(TokenStorageInterface $tokenStorage = null, AuthorizationCheckerInterface $authorizationChecker = null, EventDispatcherInterface $dispatcher = null, \Raven_Client $client = null, array $skipCapture)
    {
        // ...
    }

    public function onKernelRequest(GetResponseEvent $event)
    {
        // ...
    }

    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        // ...
    }

    public function onConsoleException(ConsoleExceptionEvent $event)
    {
        // ...
    }
}
```


### Add an EventSubscriber for Sentry Events

Create a new class, e.g. `MySentryEventSubscriber`:

```php
// src/AppBundle/EventSubscriber/MySentryEventListener.php
namespace AppBundle\EventSubscriber;

use Sentry\SentryBundle\Event\SentryUserContextEvent;
use Sentry\SentryBundle\SentrySymfonyEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MySentryEventSubscriber implements EventSubscriberInterface
{
    /** @var \Raven_Client */
    protected $client;

    public function __construct(\Raven_Client $client)
    {
        $this->client = $client;
    }

    public static function getSubscribedEvents()
    {
        // return the subscribed events, their methods and priorities
        return array(
            SentrySymfonyEvents::SET_USER_CONTEXT => 'onSetUserContext'
        );
    }

    public function onSetUserContext(SentryUserContextEvent $event)
    {
        // ...
    }
}
```

To configure the above add the following configuration to your services
definitions:

```yaml
app.my_sentry_event_subscriber:
    class: AppBundle\EventSubscriber\MySentryEventSubscriber
    arguments:
      - '@sentry.client'
    tags:
      - { name: kernel.event_subscriber }
```
