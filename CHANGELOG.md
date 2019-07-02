# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased]
...

## 3.0.0 - 2019-07-02
 - Add support for Symfony 2.8.

## 3.0.0 - 2019-05-10
 - Add the `sentry:test` command, to test if the Sentry SDK is functioning properly.

## 3.0.0-beta2 - 2019-03-22
 - Disable Sentry's ErrorHandler, and report all errors using Symfony's events (#204)

## 3.0.0-beta1 - 2019-03-06
The 3.0 major release has multiple breaking changes. The most notable one is the upgrade to the 2.0 base SDK client.
Refer to the [UPGRADE-3.0.md](https://github.com/getsentry/sentry-symfony/blob/master/UPGRADE-3.0.md) document for a
detailed explanation.
