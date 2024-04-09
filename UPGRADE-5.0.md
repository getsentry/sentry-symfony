# Upgrade 4.x to 5.0

This version adds support for the underlying [Sentry PHP SDK v4.0](https://github.com/getsentry/sentry-php).
Please refer to the PHP SDK [sentry-php/UPGRADE-4.0.md](https://github.com/getsentry/sentry-php/blob/master/UPGRADE-4.0.md) guide for a complete list of breaking changes.

- This version exclusively uses the [envelope endpoint](https://develop.sentry.dev/sdk/envelopes/) to send event data to Sentry.

  If you are using [sentry.io](https://sentry.io), no action is needed.
  If you are using an on-premise/self-hosted installation of Sentry, the minimum requirement is now version `>= v20.6.0`.

- You need to have `ext-curl` installed to use the SDK.

- The `IgnoreErrorsIntegration` integration was removed. Use the `ignore_exceptions` option instead.
  Previously, both `Symfony\Component\ErrorHandler\Error\FatalError` and `Symfony\Component\Debug\Exception\FatalErrorException` were ignored by default.
  To continue ignoring these exceptions, make the following changes to your `config/packages/sentry.yaml` file:

  ```yaml
  // config/packages/sentry.yaml

  sentry:
    options:
      ignore_exceptions:
        - 'Symfony\Component\ErrorHandler\Error\FatalError'
        - 'Symfony\Component\Debug\Exception\FatalErrorException'
  ```

  This option performs an [`is_a`](https://www.php.net/manual/en/function.is-a.php) check now, so you can also ignore more generic exceptions.

- Removed support for `guzzlehttp/psr7: ^1.8.4`.

- The `RequestFetcher` now relies on `guzzlehttp/psr7: ^2.1.1`.

- Continue traces from the W3C `traceparent` request header.
- Inject the W3C `traceparent` header on outgoing HTTP client calls.
- Added `Sentry\SentryBundle\Twig\SentryExtension::getW3CTraceMeta()`. 

- The new default value for the `sentry.options.trace_propagation_targets` option is now `null`. To not attach any headers to outgoing requests, set this option to `[]`.

- Added the `sentry.options.enable_tracing` option.
- Added the `sentry.options.attach_metric_code_locations` option.
- Added the `sentry.options.spotlight` option.
- Added the `sentry.options.spotlight_url` option.
- Added the `sentry.options.transport` option.
- Added the `sentry.options.http_client` option.
- Added the `sentry.options.http_proxy_authentication` option.
- Added the `sentry.options.http_ssl_verify_peer` option.
- Added the `sentry.options.http_compression` option.

- Removed the `sentry.transport_factory` option. Use `sentry.options.transport` to use a custom transport.
- Removed the `sentry.options.send_attempts` option. You may use a custom transport if you rely on this behaviour.
- Removed the `sentry.options.enable_compression` option. Use `sentry.options.http_compression` instead.

- Removed `Sentry\SentryBundle\Transport\TransportFactory`.
- Removed `Sentry\State\HubInterface\Sentry\State\HubInterface`.
