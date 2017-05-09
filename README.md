# sentry-symfony

Symfony integration for [Sentry](https://getsentry.com/).

[![Stable release][Last stable image]][Packagist link]
[![Unstable release][Last unstable image]][Packagist link]

[![Build status][Master build image]][Master build link]
[![Scrutinizer][Master scrutinizer image]][Master scrutinizer link]
[![Coverage Status][Master coverage image]][Master coverage link]


## Installation

### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```bash
$ composer require sentry/sentry-symfony
```

This command requires you to have Composer installed globally, as explained
in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

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
        $bundles = array(
            // ...

            new Sentry\SentryBundle\SentryBundle(),
        );

        // ...
    }

    // ...
}
```

### Step 3: Configure the SDK

Add your DSN to ``app/config/config.yml``:

```yaml

sentry:
    dsn: "https://public:secret@sentry.example.com/1"
```


## Configuration

The following can be configured via ``app/config/config.yml``:

### enabled

Enable or disable reporting to Sentry. Defaults to true.

### app_path

The base path to your application. Used to trim prefixes and mark frames as part of your application.

```yaml
sentry:
    app_path: "/path/to/myapp"
```

### dsn

[Sentry DSN](https://docs.sentry.io/quickstart/#configure-the-dsn) value of your project.
 Leaving this value empty will effectively disable Sentry reporting.

```yaml
sentry:
    dsn: "https://public:secret@sentry.example.com/1"
```

### environment

The environment your code is running in (e.g. production).

```yaml
sentry:
    environment: "%kernel.environment%"
```

### release

The version of your application. Often this is the git sha.

```yaml
sentry:
    release: "beeee2a06521a60e646bbb8fe38702e61e4929bf"
```

### prefixes

A list of prefixes to strip from filenames. Often these would be vendor/include paths.

```yaml
sentry:
    prefixes:
        - /usr/lib/include
```

### skip some exceptions

```yaml
sentry:
    skip_capture:
        - "Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface"
```

### error types

Define which error types should be reported.

```yaml
sentry:
    error_types: E_ALL & ~E_DEPRECATED & ~E_NOTICE
```

[Last stable image]: https://poser.pugx.org/sentry/sentry-symfony/version.svg
[Last unstable image]: https://poser.pugx.org/sentry/sentry-symfony/v/unstable.svg
[Master build image]: https://travis-ci.org/getsentry/sentry-symfony.svg?branch=master
[Master scrutinizer image]: https://scrutinizer-ci.com/g/getsentry/sentry-symfony/badges/quality-score.png?b=master
[Master coverage image]: https://coveralls.io/repos/getsentry/sentry-symfony/badge.svg?branch=master&service=github

[Packagist link]: https://packagist.org/packages/sentry/sentry-symfony
[Master build link]: https://travis-ci.org/getsentry/sentry-symfony
[Master scrutinizer link]: https://scrutinizer-ci.com/g/getsentry/sentry-symfony/?branch=master
[Master coverage link]: https://coveralls.io/github/getsentry/sentry-symfony?branch=master
