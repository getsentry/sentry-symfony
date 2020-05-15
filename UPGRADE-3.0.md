# Upgrade to 3.0
The 3.0 major release of this bundle has some major changes. This document will try to list them all, to help users 
during the upgrade path.

## Sentry SDK 2.0
The major change is in the fact that we now require the `sentry/sdk` metapackage; this, in turn, requires the original
`sentry/sentry` package, but at major version 2.
This new version has been completely rewritten: if you use this bundle and you interact directly with the underlying SDK
and client, you should read through the [relative upgrade document](https://github.com/getsentry/sentry-php/blob/master/UPGRADE-2.0.md).

## Changes in the configuration
There are only two BC impacting the configuration:
 * the `skip_capture` option has been **removed**: the same feature has been implemented in the new SDK, with the PHP `excluded_exceptions` option; you just have to move your values from `sentry.skip_capture` to `sentry.options.excluded_exceptions`
 * Symfony internal exceptions are no longer ignored by default: Sentry will start getting events for 404 or 403, or even 403 which are followed by a (remembered) login. Add new values to the `sentry.options.excluded_exceptions` option; notice that it works with an `instanceof` against the exception, so you could ignore multiple kind of events using a common ancestor class.
 * The `sentry.options` values reflect the options of the PHP SDK; many of those have been removed, and there are some new ones. You can read the [appropriate section of the upgrade document](https://github.com/getsentry/sentry-php/blob/master/UPGRADE-2.0.md#client-options), with the exception of the `server` to `dsn` migration, which is still handled in the same way by the bundle, under `sentry.dsn`
 * A new notable option is `send_default_pii`: it's a new option of the new SDK with by default is turned off, for GDPR compliance. You need to set `sentry.options.send_default_pii: true` to have the user's username and IP address attached to the events, as in the 2.0 bundle.

## HTTPlug
Since SDK 2.0 uses HTTPlug to remain transport-agnostic, you need to have installed two packages that provides 
[`php-http/async-client-implementation`](https://packagist.org/providers/php-http/async-client-implementation)
and [`http-message-implementation`](https://packagist.org/providers/psr/http-message-implementation).

The metapackage already solves this need, requiring the Curl client and Guzzle's message factories.

If instead you want to use a different HTTP client or message factory, you'll need to require manually those additional
packages:

```bash
composer require sentry/sentry:^2.0 php-http/guzzle6-adapter guzzlehttp/psr7
```

The `sentry/sentry` package is required directly to override `sentry/sdk`, and the other two packages are up to your choice;
in the current example, we're using both Guzzle's components (client and message factory).

## Changes in the services
Due to the SDK changes, and to follow newer Symfony best practices, the services exposed by the bundle are completely
changed:

 * All services are now private; declare public aliases to access them if needed; you can still use the Sentry SDK global
   functions if you want just to capture messages manually without injecting Sentry services in your code
 * All services uses the full qualified name of their interfaces to name them
 * The `ExceptionListener` has been split and renamed: we now have a simpler `ErrorListener`, and three other listeners
 dedicated to enriching events of data (`RequestListener`, `SubRequestListener` and `ConsoleListener`)
 * The listeners are now `final`; append your own listeners to override their behavior
 * The `SentrySymfonyEvents::PRE_CAPTURE` and `SentrySymfonyEvents::SET_USER_CONTEXT` events are dropped; if you want to inject data into your events, write your own listener in a similar fashion to `RequestListener`, using the `Hub` and the `Scope` to handle how and when the new information is attached to events
 * The listeners are registered with a priority of `1`; they will run just before the default priority of `0`, to ease
   the registration of custom listener that will change `Scope` data
 * Configuration options of the bundle are now aligned with the new ones of the 2.0 SDK

## New services
This is a brief description of the services registered by this bundle:

 * `Sentry\State\HubInterface`: this is the central root of the SDK; it's the `Hub` that the bundle will instantiate at
 startup, and the current one too if you do not change it
 * `Sentry\ClientInterface`: this is the proper client; compared to the 1.x SDK version it's a lot more slimmed down,
 since a lot of the stuff has been split in separated components, so you probably will not interact with it as much as
 in the past. You also have to remind you that the client is bound to the `Hub`, and has to be changed there if you want 
 to use a different one automatically in error reporting
 * `Sentry\ClientBuilderInterface`: this is the factory that builds the client; you can call its methods to change all
 the settings and dependencies that will be injected in the latter created client. You can use this service to obtain more
 customized clients for your needs
 * `Sentry\Options`: this class holds all the configuration used in the client and other SDK components; it's populated
 starting from the bundle configuration
