# Changelog

## Unreleased

- feat: Add support for tracing of Symfony HTTP client requests (#606)
    - feat: Add support for HTTP client baggage propagation (#663)
    - ref: Add proper HTTP client span descriptions (#680)
- feat: Support logging the impersonator user, if any (#647)
- ref: Use constant for the SDK version (#662)

## 4.4.0 (2022-10-20)

- feat: Add support for Dynamic Sampling (#665)

## 4.3.1 (2022-10-10)

- fix: Update span ops (#655)

## 4.3.0 (2022-05-30)

- Fix compatibility issue with Symfony `>= 6.1.0` (#635)
- Add `TracingDriverConnectionInterface::getNativeConnection()` method to get the original driver connection (#597)
- Add `options.http_timeout` and `options.http_connect_timeout` configuration options (#593)

## 4.2.10 (2022-05-17)

- Fix compatibility issue with Twig >= 3.4.0 (#628)

## 4.2.9 (2022-05-03)

- Fix deprecation notice thrown when instrumenting the `PDOStatement::bindParam()` method and passing `$length = null` on DBAL `2.x` (#613)

## 4.2.8 (2022-03-31)

- Fix compatibility issue with Doctrine Bundle `>= 2.6.0` (#608)

## 4.2.7 (2022-02-18)

- Fix deprecation notice thrown when instrumenting the `PDOStatement::bindParam()` method and passing `$length = null` (#586)

## 4.2.6 (2022-01-10)

- Add support for `symfony/cache-contracts` package version `3.x` (#588)

## 4.2.5 (2021-12-13)

- Add support for Symfony 6 (#566)
- Fix fatal errors logged twice on Symfony `3.4` (#570)

## 4.2.4 (2021-10-20)

- Add return typehints to the methods of the `SentryExtension` class to prepare for Symfony 6 (#563)
- Fix setting the IP address on the user context when it's not available (#565)
- Fix wrong method existence check in `TracingDriverConnection::errorCode()` (#568)
- Fix decoration of the Doctrine DBAL connection when it implemented the `ServerInfoAwareConnection` interface (#567)

## 4.2.3 (2021-09-21)

- Fix: Test if `TracingStatement` exists before attempting to create the class alias, otherwise it breaks when opcache is enabled. (#552)
- Fix: Pass logger from `logger` config option to `TransportFactory` (#555)
- Improve the compatibility layer with Doctrine DBAL to avoid deprecations notices (#553)

## 4.2.2 (2021-08-30)

- Fix missing instrumentation of the `Statement::execute()` method of Doctrine DBAL (#548)

## 4.2.1 (2021-08-24)

- Fix return type for `TracingDriver::getDatabase()` method (#541)
- Avoid throwing exception from the `TraceableCacheAdapterTrait::prune()` and `TraceableCacheAdapterTrait::reset()` methods when the decorated adapter does not implement the respective interfaces (#543)

## 4.2.0 (2021-08-12)

- Log the bus name, receiver name and message class name as event tags when using Symfony Messenger (#492)
- Make the transport factory configurable in the bundle's config (#504)
- Add the `sentry_trace_meta()` Twig function to print the `sentry-trace` HTML meta tag (#510)
- Make the list of commands for which distributed tracing is active configurable (#515)
- Introduce `TracingDriverConnection::getWrappedConnection()` (#536)
- Add the `logger` config option to ease setting a PSR-3 logger to debug the SDK (#538)
- Bump requirement for DBAL tracing to `^2.13|^3`; simplify the DBAL tracing feature (#527)

## 4.1.4 (2021-06-18)

- Fix decoration of cache adapters inheriting parent service (#525)
- Fix extraction of the username of the logged-in user in Symfony `5.3` (#518)

## 4.1.3 (2021-05-31)

- Fix missing require of the `symfony/cache-contracts` package (#506)

## 4.1.2 (2021-05-17)

- Fix the check of the existence of the `CacheItem` class while attempting to enable the cache instrumentation (#501)

## 4.1.1 (2021-05-10)

- Fix the conditions to automatically enable the cache instrumentation when possible (#487)
- Fix deprecations triggered by Symfony `5.3` (#489)
- Fix fatal error when the `SERVER_PROTOCOL` header is missing (#495)

## 4.1.0 (2021-04-19)

- Avoid failures when the `RequestFetcher` fails to translate the `Request` (#472)
- Add support for distributed tracing of Symfony request events (#423)
- Add support for distributed tracing of Twig template rendering (#430)
- Add support for distributed tracing of SQL queries while using Doctrine DBAL (#426)
- Add support for distributed tracing when running a console command (#455)
- Add support for distributed tracing of cache pools (#477)
- Add the full CLI command string to the extra context (#352)
- Deprecate the `Sentry\SentryBundle\EventListener\ConsoleCommandListener` class in favor of its parent class `Sentry\SentryBundle\EventListener\ConsoleListener` (#429)
- Lower the required version of `symfony/psr-http-message-bridge` to allow installing it on a project that uses Symfony `3.4.x` components only (#480)

## 4.0.3 (2021-03-03)

- Fix regression from #454 for `null` value on DSN not disabling Sentry (#457)

## 4.0.2 (2021-03-03)

- Add `kernel.project_dir` to `prefixes` default value to trim paths to the project root (#434)
- Fix `null`, `false` or empty value not disabling Sentry (#454)

## 4.0.1 (2021-01-26)

- Add missing `capture-soft-fails` option to the XSD schema for the XML config (#417)
- Fix regression that send PII even when the `send_default_pii` option is off (#425)
- Fix capture of console errors when the `register_error_listener` option is disabled (#427)

## 4.0.0 (2021-01-19)

**Breaking Change**: This version uses the [envelope endpoint](https://develop.sentry.dev/sdk/envelopes/). If you are
using an on-premise installation it requires Sentry version `>= v20.6.0` to work. If you are using
[sentry.io](https://sentry.io) nothing will change and no action is needed.

- Enable back all error listeners from base SDK integration (#322)
- Add `options.traces_sampler` and `options.traces_sample_rate` configuration options (#385)
- [BC BREAK] Remove the `options.project_root` configuration option. Instead of setting it, use a combination of `options.in_app_include` and `options.in_app_exclude` (#385)
- [BC BREAK] Remove the `options.excluded_exceptions` configuration option. Instead of setting it, configure the `IgnoreErrorsIntegration` integration (#385)
- [BC BREAK] Refactorize the `ConsoleCommandListener`, `ErrorListener`, `RequestListener` and `SubRequestListener` event listeners (#387)
- Registered the CLI commands as lazy services (#373)
- [BC BREAK] Refactorize the configuration tree and the definitions of some container services (#401)
- Support the XML format for the bundle configuration (#401)
- PHP 8 support (#399, thanks to @Yozhef)
- Retrieve the request from the `RequestStack` when using the `RequestIntegration` integration (#361)
- Reorganize the folder structure and change CS standard (#405)
- [BC BREAK] Remove the `monolog` configuration option. Instead, register the service manually (#406)
- [BC BREAK] Remove the `listener_priorities` configuration option. Instead, use a compiler pass to change the priority of the listeners (#407)
- Prefer usage of the existing `Symfony\Bridge\PsrHttpMessage\HttpMessageFactoryInterface` service for the `RequestFetcher` class (#409)
- [BC BREAK] Change the priorities of the `RequestListener` and `SubRequestListener` listeners (#414)

## 3.5.3 (2020-10-13)

- Refactors and fixes class aliases for more robustness (#315 #359, thanks to @guilliamxavier)

## 3.5.2 (2020-07-08)

- Use `jean85/pretty-package-versions` `^1.5` to leverage the new `getRootPackageVersion` method (c8799ac)
- Fix support for PHP preloading (#354, thanks to @annuh)
- Fix `capture_soft_fails: false` option for the Messenger (#353)

## 3.5.1 (2020-05-07)

- Capture events using the `Hub` in the `MessengerListener` to avoid loosing `Scope` data (#339, thanks to @sh41)
- Capture multiple events if multiple exceptions are generated in a Messenger Worker context (#340, thanks to @emarref)

## 3.5.0 (2020-05-04)

- Capture and flush messages in a Messenger Worker context (#326, thanks to @emarref)
- Support Composer 2 (#335)
- Avoid issues with dependency lower bound, fix #331 (#335)

## 3.4.4 (2020-03-16)

- Improve `release` option default value (#325)

## 3.4.3 (2020-02-03)

- Change default of `in_app_include` to empty, due to getsentry/sentry-php#958 (#311)
- Improve class_alias robustness (#315)

## 3.4.2 (2020-01-29)

- Remove space from classname used with `class_alias` (#313)

## 3.4.1 (2020-01-24)

- Fix issue due to usage of `class_alias` to fix deprecations, which could break BC layers of third party packages (#309, thanks to @scheb)

## 3.4.0 (2020-01-20)

- Add support for `sentry/sentry` 2.3 (#298)
- Drop support for `sentry/sentry` < 2.3 (#298)
- Add support to `in_app_include` client option (#298)
- Remap `excluded_exceptions` option to use the new `IgnoreErrorsIntegration` (#298)

## 3.3.2 (2020-01-16)

- Fix issue with exception listener under Symfony 4.3 (#301)

## 3.3.1 (2020-01-14)

- Fixed Release

## 3.3.0 (2020-01-14)

- Add support for Symfony 5.0 (#266, thanks to @Big-Shark)
- Drop support for Symfony < 3.4 (#277)
- Add default value for the `release` option, using the detected root package version (#291 #292, thanks to @Ocramius)

## 3.2.1 (2019-12-19)

- Fix handling of command with no name on `ConsoleListener` (#261)
- Remove check by AuthorizationChecker in  `RequestListener` (#264)
- Fixed undefined variable in `RequestListener` (#263)

## 3.2.0 (2019-10-04)

- Add forward compatibility with Symfony 5 (#235, thanks to @garak)
- Fix Hub initialization for `ErrorListener` (#243, thanks to @teohhanhui)
- Fix compatibility with sentry/sentry 2.2+ (#244)
- Add support for `class_serializers` option (#245)
- Add support for `max_request_body_size` option (#249)
- Add option to disable the error listener completely (#247, thanks to @HypeMC)
- Add options to register the Monolog Handler (#247, thanks to @HypeMC)

## 3.1.0 (2019-07-02)

- Add support for Symfony 2.8 (#233, thanks to @nocive)
- Fix handling of ESI requests (#213, thanks to @franmomu)

## 3.0.0 (2019-05-10)

- Add the `sentry:test` command, to test if the Sentry SDK is functioning properly.

## 3.0.0-beta2 (2019-03-22)

- Disable Sentry's ErrorHandler, and report all errors using Symfony's events (#204)

## 3.0.0-beta1 (2019-03-06)

The 3.0 major release has multiple breaking changes. The most notable one is the upgrade to the 2.0 base SDK client.
Refer to the [UPGRADE-3.0.md](https://github.com/getsentry/sentry-symfony/blob/master/UPGRADE-3.0.md) document for a
detailed explanation.
