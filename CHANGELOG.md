## 5.0.1

The Sentry SDK team is happy to announce the immediate availability of Sentry Laravel SDK v5.0.1.

### Bug Fixes

- Add missing `setCallbackWrapper` method to `TraceableCacheAdapterTrait` [(#841)](https://github.com/getsentry/sentry-symfony/pull/841)
- Fix detection of the `symfony/http-client` being installed [(#858)](https://github.com/getsentry/sentry-symfony/pull/858)

## 5.0.0

The Sentry SDK team is thrilled to announce the immediate availability of Sentry Symfony SDK v5.0.0.

### Breaking Change

Please refer to the [UPGRADE-5.0.md](https://github.com/getsentry/sentry-symfony/blob/master/UPGRADE-5.0.md) guide for a complete list of breaking changes.

This version adds support for the underlying [Sentry PHP SDK v4.0](https://github.com/getsentry/sentry-php).
Please refer to the PHP SDK [sentry-php/UPGRADE-4.0.md](https://github.com/getsentry/sentry-php/blob/master/UPGRADE-4.0.md) guide for a complete list of breaking changes.

- This version exclusively uses the [envelope endpoint](https://develop.sentry.dev/sdk/envelopes/) to send event data to Sentry.

  If you are using [sentry.io](https://sentry.io), no action is needed.
  If you are using an on-premise/self-hosted installation of Sentry, the minimum requirement is now version `>= v20.6.0`.

- You need to have `ext-curl` installed to use the SDK.

- The `IgnoreErrorsIntegration` integration was removed. Use the `ignore_exceptions` option instead.
  Previously, both `Symfony\Component\ErrorHandler\Error\FatalError` and `Symfony\Component\Debug\Exception\FatalErrorException` were ignored by default.
  To continue ignoring these exceptions, make the following changes to the config file:

  ```yaml
  // config/packages/sentry.yaml

  sentry:
    options:
      ignore_exceptions:
        - 'Symfony\Component\ErrorHandler\Error\FatalError'
        - 'Symfony\Component\Debug\Exception\FatalErrorException'
  ```

  This option performs an [`is_a`](https://www.php.net/manual/en/function.is-a.php) check now, so you can also ignore more generic exceptions.

### Features

- Add support for Sentry Developer Metrics [(#1619)](https://github.com/getsentry/sentry-php/pull/1619)

  ```php
  use function Sentry\metrics;

  // Add 4 to a counter named hits
  metrics()->increment(key: 'hits', value: 4);

  // Add 25 to a distribution named response_time with unit milliseconds
  metrics()->distribution(key: 'response_time', value: 25, unit: MetricsUnit::millisecond());

  // Add 2 to gauge named parallel_requests, tagged with type: "a"
  metrics()->gauge(key: 'parallel_requests', value: 2, tags: ['type': 'a']);

  // Add a user's email to a set named users.sessions, tagged with role: "admin"
  metrics()->set('users.sessions', 'jane.doe@example.com', null, ['role' => User::admin()]);
  ```

  Metrics are automatically sent to Sentry at the end of a request, hooking into Symfony's `kernel.terminate` event.

- Add new fluent APIs [(#1601)](https://github.com/getsentry/sentry-php/pull/1601)

  ```php
  // Before
  $transactionContext = new TransactionContext();
  $transactionContext->setName('GET /example');
  $transactionContext->setOp('http.server');

  // After
  $transactionContext = (new TransactionContext())
      ->setName('GET /example');
      ->setOp('http.server');
  ```

- Simplify the breadcrumb API [(#1603)](https://github.com/getsentry/sentry-php/pull/1603)

  ```php
  // Before
  \Sentry\addBreadcrumb(
      new \Sentry\Breadcrumb(
          \Sentry\Breadcrumb::LEVEL_INFO,
          \Sentry\Breadcrumb::TYPE_DEFAULT,
          'auth',                // category
          'User authenticated',  // message (optional)
          ['user_id' => $userId] // data (optional)
      )
  );

  // After
  \Sentry\addBreadcrumb(
      category: 'auth',
      message: 'User authenticated', // optional
      metadata: ['user_id' => $userId], // optional
      level: Breadcrumb::LEVEL_INFO, // set by default
      type: Breadcrumb::TYPE_DEFAULT, // set by default
  );
  ```

- New default cURL HTTP client [(#1589)](https://github.com/getsentry/sentry-php/pull/1589)

  The SDK now ships with its own HTTP client based on cURL. A few new options were added.

  ```yaml
  // config/packages/sentry.yaml

  sentry:
    options:
      - http_proxy_authentication: 'username:password' // user name and password to use for proxy authentication
      - http_ssl_verify_peer: false // default true, verify the peer's SSL certificate
      - http_compression: false // default true, http request body compression
  ```

  To use a different client, you may use the `http_client` option.
  To use a different transport, you may use the `transport` option. A custom transport must implement the `TransportInterface`.
  If you use the `transport` option, the `http_client` option has no effect.

### Misc

- The abandoned package `php-http/message-factory` was removed.
