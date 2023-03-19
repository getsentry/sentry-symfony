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

![CI](https://github.com/getsentry/sentry-symfony/workflows/CI/badge.svg) [![Coverage Status][Master Code Coverage Image]][Master Code Coverage]
[![Discord](https://img.shields.io/discord/621778831602221064)](https://discord.gg/cWnMQeA)

This is the official Symfony SDK for [Sentry](https://getsentry.com/).

## Getting Started

Using this `sentry-symfony` SDK provides you with the following benefits:

 * Quickly integrate and configure Sentry for your Symfony app
 * Out of the box, each event will contain the following data by default 
   - The currently authenticated user
   - The Symfony environment

### Install

To install the SDK you will need to be using [Composer]([https://getcomposer.org/)
in your project. To install it please see the [docs](https://getcomposer.org/download/).

```bash
composer require sentry/sentry-symfony
```

If you're using the [Symfony Flex](https://symfony.com/doc/current/setup/flex.html) Composer plugin, you might encounter a message similar to this:

```
The recipe for this package comes from the "contrib" repository, which is open to community contributions.
Review the recipe at https://github.com/symfony/recipes-contrib/tree/master/sentry/sentry-symfony/3.0

Do you want to execute this recipe?
```

Just type `y`, press return, and the procedure will continue.

**Caution:** Due to a bug in the [`SensioFrameworkExtra`](https://github.com/sensiolabs/SensioFrameworkExtraBundle) bundle, affecting version 6.0 and below, you might run into a missing `Nyholm\Psr7\Factory\Psr17Factory::class` error while executing the commands mentioned above.
If you are not using the PSR-7 bridge, you can work around this issue by changing the configuration of the bundle as follows:

```yaml
sensio_framework_extra:
   psr_message:
      enabled: false
```

For more details about the issue see https://github.com/sensiolabs/SensioFrameworkExtraBundle/pull/710.

### Enable the Bundle

If you installed the package using the Flex recipe, the bundle will be automatically enabled. Otherwise, enable it by adding it to the list
of registered bundles in the `Kernel.php` file of your project:

```php
class AppKernel extends \Symfony\Component\HttpKernel\Kernel
{
    public function registerBundles(): array
    {
        return [
            // ...
            new \Sentry\SentryBundle\SentryBundle(),
        ];
    }

    // ...
}
```

The bundle will be enabled in all environments by default.
To enable event reporting, you'll need to add a DSN (see the next step).

### Configure

Add the [Sentry DSN](https://docs.sentry.io/quickstart/#configure-the-dsn) of your project.
If you're using Symfony 3.4, add the DSN to your `app/config/config_prod.yml` file.
For Symfony 4 or newer, add the DSN to your `config/packages/sentry.yaml` file.

Keep in mind that by leaving the `dsn` value empty (or undeclared), you will disable Sentry's event reporting.

```yaml
sentry:
    dsn: "https://public:secret@sentry.example.com/1"
    messenger: 
        enabled: true # flushes Sentry messages at the end of each message handling
        capture_soft_fails: true # captures exceptions marked for retry too
    options:
        environment: '%kernel.environment%'
        release: '%env(VERSION)%' #your app version
```

The parameter `options` allows to fine-tune exceptions. To discover more options, please refer to
[the Unified APIs](https://docs.sentry.io/development/sdk-dev/unified-api/#options) options and
the [PHP specific](https://docs.sentry.io/platforms/php/#php-specific-options) ones.

#### Optional: use custom HTTP factory/transport

Since the SDK 2.0 uses HTTPlug to remain transport-agnostic, you need to install two packages that provide 
[`php-http/async-client-implementation`](https://packagist.org/providers/php-http/async-client-implementation)
and [`psr/http-message-implementation`](https://packagist.org/providers/psr/http-message-implementation).

This bundle depends on `sentry/sdk`, which is a metapackage that already solves this need, requiring our suggested HTTP
packages: the Curl client and Guzzle's message factories.

Instead, if you want to use a different HTTP client or message factory, you can override the ``sentry/sdk`` package by adding the following to your ``composer.json`` after the ``require`` section:
```json
    "replace": {
        "sentry/sdk": "*"
    }
```

For example for using Guzzle's components: 

```bash
composer require php-http/guzzle6-adapter guzzlehttp/psr7
```

A possible alternate solution is using `pugx/sentry-sdk`, a metapackage that replaces `sentry/sdk` and uses `symfony/http-client` instead of `guzzlehttp/guzzle`:

```bash
composer require pugx/sentry-sdk
```

## Maintained versions

 * 4.x is actively maintained and developed on the master branch, and uses Sentry SDK 3.0;
 * 3.x is supported only for fixes and uses Sentry SDK 2.0;
 * 2.x is no longer maintained; from this version onwards it requires Symfony 3+ and PHP 7.1+;
 * 1.x is no longer maintained; you can use it for Symfony < 2.8 and PHP 5.6/7.0; 
 * 0.8.x is no longer maintained.

### Upgrading to 4.0

The 4.0 version of the bundle uses the newest version (3.x) of the underlying Sentry SDK. If you need to migrate from previous versions, please check the `UPGRADE-4.0.md` document.

#### Custom serializers

The option class_serializers can be used to send customized objects serialization.
```yml
sentry:
    options:
        class_serializers:
            YourValueObject: 'ValueObjectSerializer'
```

Several serializers can be added and the serializable check is done by using the **instanceof** type operator.
The serializer must implement the `__invoke` method, which needs to return an **array**, containing the information that should be send to Sentry. The class name is always sent by default.

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

## Contributing to the SDK

Please refer to [CONTRIBUTING.md](CONTRIBUTING.md).

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
