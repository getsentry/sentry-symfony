# sentry-symfony

Symfony integration for [Sentry](https://getsentry.com/).

[![Stable release][Last stable image]][Packagist link]
[![Unstable release][Last unstable image]][Packagist link]

[![Build status][Master build image]][Master build link]
[![Scrutinizer][Master scrutinizer image]][Master scrutinizer link]
[![Coverage Status][Master coverage image]][Master scrutinizer link]

## Benefits

Use sentry-symfony for:

 * A fast sentry setup
 * Easy configuration in your Symfony app
 * Automatic wiring in your app. Each event has the following things added automatically to it:
   - user
   - Symfony environment
   - app path
   - excluded paths (cache and vendor)

## Installation

### Step 1: Download the Bundle
You can install this bundle using Composer: 

```bash
composer require sentry/sentry-symfony:^3.0
```

#### Optional: use custom HTTP factory/transport
*Note: this step is optional*

Since SDK 2.0 uses HTTPlug to remain transport-agnostic, you need to have installed two packages that provides 
[`php-http/async-client-implementation`](https://packagist.org/providers/php-http/async-client-implementation)
and [`http-message-implementation`](https://packagist.org/providers/psr/http-message-implementation).

This bundle depends on `sentry/sdk`, which is a metapackage that already solves this need, requiring our suggested HTTP
packages: the Curl client and Guzzle's message factories.

If instead you want to use a different HTTP client or message factory, you'll need to require manually those additional
packages:

```bash
composer require sentry/sentry-symfony:^3.0 sentry/sentry:^2.0 php-http/guzzle6-adapter guzzlehttp/psr7
```

The `sentry/sentry` package is required directly to override `sentry/sdk`, and the other two packages are up to your choice;
in the current example, we're using both Guzzle's components (client and message factory).

> TODO: Flex recipe

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
        $bundles = [
            // ...
            new Sentry\SentryBundle\SentryBundle(),
        ];

        // ...
    }

    // ...
}
```
Note that, unlike before in version 3, the bundle will be enabled in all environments; event reporting, instead, is enabled
only when providing a DSN (see the next step).

### Step 3: Configure the SDK

Add your [Sentry DSN](https://docs.sentry.io/quickstart/#configure-the-dsn) value of your project to ``app/config/config_prod.yml``.
Leaving this value empty (or undeclared) in other environments will effectively disable Sentry reporting.

```yaml
sentry:
    dsn: "https://public:secret@sentry.example.com/1"
    options:
        environment: '%kernel.environment%'
        release: '%env(VERSION)%' #your app version
        excluded_exceptions: #exclude validation errors
            - App\Exception\UserNotFoundException
            - Symfony\Component\Security\Core\Exception\AccessDeniedException
```

The parameter `options` allows to fine-tune exceptions. To discover more options, please refer to
[the Unified APIs](https://docs.sentry.io/development/sdk-dev/unified-api/#options) options and
the [PHP specific](https://docs.sentry.io/platforms/php/#php-specific-options) ones.

#### Optional: use monolog handler provided by `sentry/sentry`
*Note: this step is optional*

If You're using `monolog` for logging e.g. in-app errors, You
can use this handler in order for them to show up in Sentry. 

First, define `Sentry\Monolog\Handler` as a service in `config/services.yaml`

```yaml
services:
    sentry.monolog.handler:
        class: Sentry\Monolog\Handler
```

Then enable it in `monolog` config:

```yaml
monolog:
    handlers:
        sentry:
            type: service
            id: sentry.monolog.handler
            level: error 
```

## Maintained versions
 * 3.x is actively maintained and developed on the master branch, and uses Sentry SDK 2.0;
 * 2.x is supported only for fixes; from this version onwards it requires Symfony 3+ and PHP 7.1+;
 * 1.x is no longer maintained; you can use it for Symfony < 2.8 and PHP 5.6/7.0; 
 * 0.8.x is no longer maintained.

### Upgrading to 3.0
The 3.0 version of the bundle uses the newest version (2.x) of the underlying Sentry SDK. If you need to migrate from previous versions, please check the `UPGRADE-3.0.md` document.

## Customization

The Sentry 2.0 SDK uses the Unified API, hence it uses the concept of `Scope`s to hold information about the current 
state of the app, and attach it to any event that is reported. This bundle has three listeners (`RequestListener`, 
`SubRequestListener` and `ConsoleListener`) that adds some easy default information. 

Those listeners normally are executed with a priority of `1` to allow easier customization with custom listener, that by 
default run with a lower priority of `0`.

Those listeners are `final` so not extendable, but you can look at those to know how to add more information to the 
current `Scope` and enrich you Sentry events.

[Last stable image]: https://poser.pugx.org/sentry/sentry-symfony/version.svg
[Last unstable image]: https://poser.pugx.org/sentry/sentry-symfony/v/unstable.svg
[Master build image]: https://travis-ci.org/getsentry/sentry-symfony.svg?branch=master
[Master scrutinizer image]: https://scrutinizer-ci.com/g/getsentry/sentry-symfony/badges/quality-score.png?b=master
[Master coverage image]: https://scrutinizer-ci.com/g/getsentry/sentry-symfony/badges/coverage.png?b=master

[Packagist link]: https://packagist.org/packages/sentry/sentry-symfony
[Master build link]: https://travis-ci.org/getsentry/sentry-symfony
[Master scrutinizer link]: https://scrutinizer-ci.com/g/getsentry/sentry-symfony/?branch=master
