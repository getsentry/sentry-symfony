<p align="center">
  <a href="https://sentry.io/?utm_source=github&utm_medium=logo" target="_blank">
    <img src="https://sentry-brand.storage.googleapis.com/sentry-wordmark-dark-280x84.png" alt="Sentry" width="280" height="84">
  </a>
</p>

_Bad software is everywhere, and we're tired of it. Sentry is on a mission to help developers write better software faster, so we can get back to enjoying technology. If you want to join us [<kbd>**Check out our open positions**</kbd>](https://sentry.io/careers/)_

# Official Sentry SDK for Symfony

[![Stable release][Last stable image]][Packagist link]
[![License](https://poser.pugx.org/sentry/sentry-symfony/license)](https://packagist.org/packages/sentry/sentry-symfony)
[![Total Downloads](https://poser.pugx.org/sentry/sentry-symfony/downloads)](https://packagist.org/packages/sentry/sentry-symfony)
[![Monthly Downloads](https://poser.pugx.org/sentry/sentry-symfony/d/monthly)](https://packagist.org/packages/sentry/sentry-symfony)

[![CI](https://github.com/getsentry/sentry-symfony/actions/workflows/tests.yaml/badge.svg)](https://github.com/getsentry/sentry-symfony/actions/workflows/tests.yaml)
[![Coverage Status][Master Code Coverage Image]][Master Code Coverage]
[![Discord](https://img.shields.io/discord/621778831602221064)](https://discord.gg/cWnMQeA)
[![X Follow](https://img.shields.io/twitter/follow/sentry?label=sentry&style=social)](https://x.com/intent/follow?screen_name=sentry)

This is the official Symfony SDK for [Sentry](https://getsentry.com/).

## Getting Started

### Install

Install the SDK using [Composer](https://getcomposer.org/).

```bash
composer require sentry/sentry-symfony
```

### Configure

Add the [Sentry DSN](https://docs.sentry.io/quickstart/#configure-the-dsn) to your `.env` file.

```
###> sentry/sentry-symfony ###
SENTRY_DSN="https://public@sentry.example.com/1"
###< sentry/sentry-symfony ###
```

### Usage

```php
use function Sentry\captureException;

try {
    $this->functionThatMayFail();
} catch (\Throwable $exception) {
    captureException($exception);
}
```

## Contributing to the SDK

Please refer to [CONTRIBUTING.md](CONTRIBUTING.md).

### Thanks to all the people who contributed so far!

<a href="https://github.com/getsentry/sentry-symfony/graphs/contributors">
  <img src="https://contributors-img.web.app/image?repo=getsentry/sentry-symfony" />
</a>

## Getting help/support

If you need help setting up or configuring the Symfony SDK (or anything else in the Sentry universe) please head over to the [Sentry Community on Discord](https://discord.com/invite/Ww9hbqr). There is a ton of great people in our Discord community ready to help you!

## Resources

- [![Documentation](https://img.shields.io/badge/documentation-sentry.io-green.svg)](https://docs.sentry.io/quickstart/)
- [![Discord](https://img.shields.io/discord/621778831602221064)](https://discord.gg/Ww9hbqr)
- [![Stack Overflow](https://img.shields.io/badge/stack%20overflow-sentry-green.svg)](http://stackoverflow.com/questions/tagged/sentry)
- [![Twitter Follow](https://img.shields.io/twitter/follow/getsentry?label=getsentry&style=social)](https://twitter.com/intent/follow?screen_name=getsentry)

## License

Licensed under the MIT license, see [`LICENSE`](LICENSE)

[Last stable image]: https://poser.pugx.org/sentry/sentry-symfony/version.svg
[Packagist link]: https://packagist.org/packages/sentry/sentry-symfony
[Master Code Coverage]: https://codecov.io/gh/getsentry/sentry-symfony/branch/master
[Master Code Coverage Image]: https://img.shields.io/codecov/c/github/getsentry/sentry-symfony/master?logo=codecov

