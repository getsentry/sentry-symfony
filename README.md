# sentry-symfony

Symfony integration for [Sentry](https://getsentry.com/).

[![Stable release][Last stable image]][Packagist link]
[![Total Downloads](https://poser.pugx.org/sentry/sentry/downloads)](https://packagist.org/packages/sentry/sentry)
[![Monthly Downloads](https://poser.pugx.org/sentry/sentry/d/monthly)](https://packagist.org/packages/sentry/sentry)
[![License](https://poser.pugx.org/sentry/sentry/license)](https://packagist.org/packages/sentry/sentry)

[![Build Status][Travis Master Build Status Image]][Travis Build Status] [![Coverage Status][Master Code Coverage Image]][Master Code Coverage]
[![Discord](https://img.shields.io/discord/621778831602221064)](https://discord.gg/cWnMQeA)

## Benefits

Use `sentry-symfony` for:

 * A fast Sentry setup
 * Easy configuration in your Symfony app
 * Automatic wiring in your app. Each event has the following things added automatically to it:
   - user
   - Symfony environment
   - app path
   - excluded paths (cache and vendor)

## Installation with Symfony Flex (Symfony 4 or newer):
If you're using the [Symfony Flex](https://symfony.com/doc/current/setup/flex.html) Composer plugin, you can install this bundle in a single, easy step: 
```bash
composer require sentry/sentry-symfony
```
This could show a message similar to this:
```
   The recipe for this package comes from the "contrib" repository, which is open to community contributions.
    Review the recipe at https://github.com/symfony/recipes-contrib/tree/master/sentry/sentry-symfony/3.0

    Do you want to execute this recipe?
```
Press `y` and return to allow the installation.

## Installation without Symfony Flex:
### Step 1: Download the Bundle
You can install this bundle using Composer: 

```bash
composer require sentry/sentry-symfony:^3.0
```

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

## Configuration of the SDK

Add your [Sentry DSN](https://docs.sentry.io/quickstart/#configure-the-dsn) value of your project, if you have Symfony 3.4 add it to ``app/config/config_prod.yml`` for Symfony 4 or newer add the value to `config/packages/sentry.yaml`.
Keep in mind that leaving the `dsn` value empty (or undeclared) in other environments will effectively disable Sentry reporting.

```yaml
sentry:
    dsn: "https://public:secret@sentry.example.com/1"
    messenger: 
        enabled: true # flushes Sentry messages at the end of each message handling
        capture_soft_fails: true # captures exceptions marked for retry too
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

#### Optional: use monolog handler provided by `sentry/sentry` (available since 3.2.0)
*Note: this step is optional*

If you're using `monolog` for logging e.g. in-app errors, you
can use this handler in order for them to show up in Sentry. 

First, enable & configure the `Sentry\Monolog\Handler`; you'll also need
to disable the `Sentry\SentryBundle\EventListener\ErrorListener` to
avoid having duplicate events in Sentry:

```yaml
sentry:
    register_error_listener: false # Disables the ErrorListener
    monolog:
        error_handler:
            enabled: true
            level: error
```

Then enable it in `monolog` config:

```yaml
monolog:
    handlers:
        sentry:
            type: service
            id: Sentry\Monolog\Handler
```

Additionally, you can register the `PsrLogMessageProcessor` to resolve PSR-3 placeholders in reported messages:

```yaml
services:
    Monolog\Processor\PsrLogMessageProcessor:
        tags: { name: monolog.processor, handler: sentry }
```

#### Optional: use custom HTTP factory/transport

Since SDK 2.0 uses HTTPlug to remain transport-agnostic, you need to have installed two packages that provides 
[`php-http/async-client-implementation`](https://packagist.org/providers/php-http/async-client-implementation)
and [`http-message-implementation`](https://packagist.org/providers/psr/http-message-implementation).

This bundle depends on `sentry/sdk`, which is a metapackage that already solves this need, requiring our suggested HTTP
packages: the Curl client and Guzzle's message factories.

If instead you want to use a different HTTP client or message factory, you can override the ``sentry/sdk`` package adding the following to your ``composer.json`` after the ``require`` section:
```yaml
    "replace": {
        "sentry/sdk": "*"
    }
```
This will prevent the installation of ``sentry/sdk`` package and will allow you to install through Composer the HTTP client or message factory of your choice.

For example for using Guzzle's components: 

```bash
composer require php-http/guzzle6-adapter guzzlehttp/psr7
```

A possible alternate solution is using `pugx/sentry-sdk`, a metapackage that replaces `sentry/sdk` and uses `symfony/http-client` instead of `guzzlehttp/guzzle`:

```bash
composer require pugx/sentry-sdk
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
`SubRequestListener` and `ConsoleListener`) that adds some easy default information. Since 3.5, a fourth listener has been added to handle the case of Messenger Workers: `MessengerListener`.

Those listeners normally are executed with a priority of `1` to allow easier customization with custom listener, that by 
default run with a lower priority of `0`.

Those listeners are `final` so not extendable, but you can look at those to know how to add more information to the 
current `Scope` and enrich you Sentry events.


#### Services configuration

Services registered by the bundle (the `ClientBuilder` in the example below) can be configured in several ways, one of them is by using a [compiler pass](https://symfony.com/doc/current/service_container/compiler_passes.html):

```php
final class SentryCustomizationCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $containerBuilder): void
    {
        // Example - setting own serializer to Sentry\ClientBuilder
        $containerBuilder->getDefinition(ClientBuilderInterface::class)
            ->addMethodCall('setSerializer', [
                new Reference(MyCustomSerializer::class),
            ]);
    }
}
```

#### Custom serializers

The option class_serializers can be used to send customized objects serialization.
```yml
sentry:
    options:
        class_serializers:
            YourValueObject: '@ValueObjectSerializer'
```

Several serializers can be added and the serializable check is done using **instanceof**. The serializer must implements the `__invoke` method returning an **array** with the information to send to sentry (class name is always sent).

Serializer example:
```php
final class ValueObjectSerializer
{
    public function __invoke(YourValueObject $vo): array
    {
        return [
            'value' => $vo->value()
        ];
    }
}
```

[Last stable image]: https://poser.pugx.org/sentry/sentry-symfony/version.svg
[Packagist link]: https://packagist.org/packages/sentry/sentry-symfony
[Travis Build Status]: http://travis-ci.org/getsentry/sentry-symfony
[Travis Master Build Status Image]: https://img.shields.io/travis/getsentry/sentry-symfony/master?logo=travis
[Master Code Coverage]: https://codecov.io/gh/getsentry/sentry-symfony/branch/master
[Master Code Coverage Image]: https://img.shields.io/codecov/c/github/getsentry/sentry-symfony/master?logo=codecov
