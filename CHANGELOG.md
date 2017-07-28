# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased]

## 0.8.2 - 2017-07-28
### Fixed
 - Fix previous release with cherry pick of the right commit from #67

## 0.8.1 - 2017-07-27
### Fixed
 - Force load of client in console commands to avoid missing notices due to lazy-loading (#67) 

## 0.8.0 - 2017-06-19
### Added
 - Add `SentryExceptionListenerInterface` and the `exception_listener` option in the configuration (#47) to allow customization of the exception listener
 - Add `SentrySymfonyEvents::PRE_CAPTURE` and `SentrySymfonyEvents::SET_USER_CONTEXT` events (#47) to customize event capturing information 
 - Make listeners' priority customizable through the new `listener_priorities` configuration key
### Fixed
 - Make SkipCapture work on console exceptions too

## 0.7.1 - 2017-01-26
### Fixed
- Quote sentry.options in services.yml.

## 0.7.0 - 2017-01-20
### Added
- Expose all configuration options (#36).

## 0.6.0 - 2016-10-24
### Fixed
- Improve app path detection to exclude root folder and exclude vendor.

## 0.5.0 - 2016-09-08
### Changed
- Raise sentry/sentry minimum requirement to ## 1.2.0. - 2017-xx-xx Fixed an issue with a missing import (#24)### . - 2017-xx-xx ``prefixes`` and ``app_path`` will now be bound by default.

## 0.4.0 - 2016-07-21
### Added
- Added ``skip_capture`` configuration for excluding exceptions.
### Changed
- Security services are now optional.
- Console exceptions are now captured.
- Default PHP SDK hooks will now be installed (via ``Raven_Client->install``).
- SDK will now be registered as 'sentry-symfony'.

## 0.3.0 - 2016-05-19
### Added
- Added support for capturing the current user.
