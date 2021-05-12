# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## Unreleased

## 3.5.4 (2021-05-13)
 - Fix deprecations triggered by Symfony 5.3 (#490, thanks to @derrabus)
 - CLI commands registration policy changed to lazy load (#373, thanks to @kefzce)
 - Escape release option if it contains a `/` (#371, thanks to @VincentLanglet)

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
