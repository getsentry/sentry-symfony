# Upgrade to 3.0
The 3.0 major release of this bundle has some major changes. This document will try to list them all, to help users 
during the upgrade path.

## Sentry SDK 2.0
The major change is in the fact that we now require the underlying `sentry/sentry` package to have version 2.
This new version has been completely rewritten: if you use this bundle and you interact directly with the underlying SDK
and client, you should read through the [relative upgrade document](https://github.com/getsentry/sentry-php/blob/master/UPGRADE-2.0.md).

## HTTPlug
Since SDK 2.0 uses HTTPlug to remain transport-agnostic, you need to manually require two packages that provides 
[`php-http/async-client-implementation`](https://packagist.org/providers/php-http/async-client-implementation)
and [`http-message-implementation`](https://packagist.org/providers/psr/http-message-implementation).

For example, if you want to install/upgrade using Curl as transport and the PSR-7 implementation by Guzzle, you can use:

```bash
composer require sentry/sentry:2.0.0-beta1 php-http/curl-client guzzlehttp/psr7
```

## Changes in the services
Due to the SDK changes, and to follow newer Symfony best practices, the services exposed by the bundle are completely
changed:

 * All services are now private; declare public aliases to access them if needed; you can still use the Sentry SDK global
   functions if you want just to capture messages manually without injecting Sentry services in your code
 * All services uses the full qualified name of the interfaces to name them
 * The `ExceptionListener` has been splitted in two: `RequestListener` and `ConsoleListener`
 * The listeners are now `final`; append your own listeners to override their behavior
 * The listeners are registered with a priority of `1`; they will run just before the default priority of `0`, to ease
   the registration of custom listener that will change `Scope` data
 * Configuration options of the bundle are now aligned with the new ones of the 2.0 SDK
 * Listeners are no longer used to capture exceptions, it's all demanded to the error handler

## New services
This is a brief description of the services registered by this bundle:

 * `Sentry\State\HubInterface`: this is the central root of the SDK; it's the `Hub` that the bundle will instantiate at
 startup, and the current one too if you do not change it
 * `Sentry\ClientInterface`: this is the proper client; compared to the 1.x SDK version it's a lot more slimmed down,
 since a lot of the stuff has been splitted in separated components, so you probably will not interact with it as much as
 in the past. You also have to remind you that the client is bound to the `Hub`, and has to be changed there if you want 
 to use it automatically in error reporting
 * `Sentry\ClientBuilderInterface`: this is the factory that builds the client; you can call its methods to change all
 the settings and dependencies that will be injected in the latter created client. You can use this service to obtain more
 customized clients for your needs
 * `Sentry\Options`: this class holds all the configuration used in the client and other SDK components; it's populated
 starting from the bundle configuration
