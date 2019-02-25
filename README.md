# sentry-symfony

Symfony integration for [Sentry](https://getsentry.com/).

[![Stable release][Last stable image]][Packagist link]
[![Unstable release][Last unstable image]][Packagist link]

[![Build status][Master build image]][Master build link]
[![Scrutinizer][Master scrutinizer image]][Master scrutinizer link]
[![Coverage Status][Master coverage image]][Master scrutinizer link]

## Notice 3.0
> The current master branch contains the 3.0 version of this bundle, which is currently under development. This version
> will support the newest 2.0 version of the underlying Sentry SDK version.
>
> A beta version will be tagged as soon as possible, in the meantime you can continue to use the previous versions.
> 
> To know more about the progress of this version see [the relative milestone](https://github.com/getsentry/sentry-symfony/milestone/3)

## Benefits

Use sentry-symfony for:

 * A fast sentry setup
 * Access to the `sentry.client` through the container
 * Automatic wiring in your app. Each event has the following things added automatically to it:
   - user
   - Symfony environment
   - app path
   - hostname
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
Note that, unlike before in version 3, the bundle will be enabled in all environments.

### Step 3: Configure the SDK

Add your [Sentry DSN](https://docs.sentry.io/quickstart/#configure-the-dsn) value of your project to ``app/config/config.yml``.
Leaving this value empty will effectively disable Sentry reporting.

```yaml
sentry:
    dsn: "https://public:secret@sentry.example.com/1"
```

## Maintained versions
 * 3.x is actively maintained and developed on the master branch, and uses Sentry SDK 2.0;
 * 2.x is supported only for fixes; from this version onwards it requires Symfony 3+ and PHP 7.1+;
 * 1.x is no longer maintained; you can use it for Symfony < 2.8 and PHP 5.6/7.0; 
 * 0.8.x is no longer maintained.

## Configuration

TODO

## Customization

The Sentry 2.0 SDK uses the Unified API, hence it uses the concept of `Scope`s to hold information about the current 
state of the app, and attach it to any event that is reported. This bundle has two listeners (`RequestListener` and 
`ConsoleListener`) that adds some easy default information. Those listeners normally are executed with a priority of `1`
to allow easier customization with custom listener, that by default run with a lower priority of `0`.

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
