# CHANGELOG

## 6.0.0-beta1

The Sentry SDK team is happy to announce the first beta release of Sentry Symfony SDK v6.0.0.

This is a pre-release of the next major version. The API can still change before the stable release, so we do not recommend it for production yet. To try it out, install the beta explicitly. The PHP SDK has to be listed as well, because Composer does not install pre-releases of a dependency on its own:

```bash
composer require "sentry/sentry-symfony:^6.0@beta" "sentry/sentry:^5.0@beta"
```

### Breaking Changes

Please refer to the [UPGRADE-6.0.md](https://github.com/getsentry/sentry-symfony/blob/6.x/UPGRADE-6.0.md) guide for a complete list of breaking changes and how to migrate.

<details>
<summary>Upgrading with an AI coding agent? Use this prompt.</summary>

```text
Upgrade this project from version 5 to version 6 of the Sentry Symfony SDK (sentry/sentry-symfony).

1. Run `composer require "sentry/sentry-symfony:^6.0@beta" "sentry/sentry:^5.0@beta"`. If the
   cache:clear script that Composer runs at the end fails, the packages are installed anyway and
   the next steps fix the cause.
2. Read vendor/sentry/sentry-symfony/UPGRADE-6.0.md completely. It is the source of truth for every
   breaking change. If the project also uses the PHP SDK directly (the Sentry\ namespace or the
   \Sentry\...() helper functions), read vendor/sentry/sentry/UPGRADE-5.0.md as well. Version 6 is
   newer than what you know about these SDKs, so do not rely on your memory of their API. When the
   guides do not cover something, read the code in vendor/sentry/sentry-symfony/src and
   vendor/sentry/sentry/src instead of guessing.
3. Search the whole project for usages of the SDK: the sentry configuration in config/packages,
   including the when@<env> blocks and the files of each environment, the SENTRY_* variables in the
   .env files, the Monolog handlers in config/packages/monolog.yaml, service definitions that
   reference Sentry classes, type-hints on Sentry\State\HubInterface, everything from the Sentry\
   namespace, and classes that extend or decorate SDK types.
4. Apply the changes from the guides to every usage you found.
5. Build the container of every environment the project deploys, for example with
   `php bin/console lint:container --env=prod`. Then run the static analysis and the tests of the
   project, if it has them, and fix what the upgrade broke.

Some changes do not cause an error where you would look for it. Check each of these explicitly:

- Removed options make the container fail with an "Unrecognized option" error, but only in the
  environments that load them. The sentry configuration usually sits under when@prod, so the dev
  and test environments keep working while production breaks. Compare every option with the guide.
- The send_default_pii option is gone, and the defaults of the data_collection option collect more
  data than before, whichever value send_default_pii had. What gets collected is a privacy decision
  that is mine to make: apply the restrictive configuration from the guide, which is the closest to
  the old behavior, and list what I could enable on top of it. This only applies if data_collection
  is missing from the configuration or set to null. If the project already configures
  data_collection, send_default_pii was already ignored and the existing data_collection
  configuration keeps applying, so only remove send_default_pii.
- Monolog handlers of the type sentry and services of the class Sentry\Monolog\Handler no longer
  work, and neither does a hub_id or a HubInterface argument. Replace them with one of the handlers
  from the guide, registered as a handler of the type service.
- The enable_logs option is gone, so a Monolog LogsHandler sends logs whenever it is configured.
  Tell me if the project relied on enable_logs to turn it off in some environments.
- The client is never null anymore. Remove the null checks, and tell me where one of them was used
  to detect that the SDK is not configured.
- Scope data that is set before a request is handled, for example in Kernel::boot() or in the
  boot() method of a bundle, is no longer part of the events of that request. The same goes for the
  messages of a Messenger worker when isolate_context_by_message is enabled. Move the data that
  should apply to every request or message to the global scope, and tell me where you did.

When you are done, give me a summary of what you changed and a separate list of what I have to
decide or check myself. Include the changes that affect what shows up in Sentry: transactions with
a 404 status are no longer sent, error events can get a different transaction name, logs carry a
span_id instead of the sentry.trace.parent_span_id attribute, and Monolog logs with PSR-3
placeholders are sent with the interpolated message.
```

</details>

- Require version 5 of the PHP SDK. Its Hub has been removed, so the `Sentry\State\HubInterface` service is no longer available. Use the functions of the PHP SDK instead, which work on the scopes of the current request, command or message. [(#1108)](https://github.com/getsentry/sentry-symfony/pull/1108)

  ```php
  // Before
  public function __construct(private \Sentry\State\HubInterface $hub) {}

  public function __invoke(): void
  {
      $this->hub->captureException($exception);
  }

  // After
  public function __invoke(): void
  {
      \Sentry\captureException($exception);
  }
  ```

- The callbacks of `\Sentry\configureScope()` receive a `Sentry\State\IsolationScope` instead of a `Sentry\State\Scope`. The `\Sentry\withScope()` function has been removed from the PHP SDK, use `\Sentry\withIsolationScope()` instead. [(#1108)](https://github.com/getsentry/sentry-symfony/pull/1108)

- The scope of a request starts empty and no longer inherits the data that was set before the request was handled, for example in `Kernel::boot()`. The same applies to the messages of a Messenger worker when `isolate_context_by_message` is enabled. Set data that applies to every request or message on the global scope. [(#1108)](https://github.com/getsentry/sentry-symfony/pull/1108)

- The `Sentry\Monolog\Handler` has been removed from the PHP SDK, and with it the `sentry` handler type of the MonologBundle stops working. Register the `Sentry\Monolog\LogToSentryIssueHandler` or `Sentry\Monolog\ExceptionToSentryIssueHandler` as a `service` handler instead. The Monolog handlers no longer take a `HubInterface` as their first constructor argument. [(#1108)](https://github.com/getsentry/sentry-symfony/pull/1108)

  ```yaml
  services:
      Sentry\Monolog\LogToSentryIssueHandler:
          arguments:
              - !php/const Monolog\Logger::ERROR

  monolog:
      handlers:
          sentry:
              type: service
              id: Sentry\Monolog\LogToSentryIssueHandler
  ```

- The `send_default_pii` option has been removed. The `data_collection` option now always decides which data the SDK collects, and its defaults collect more data than `send_default_pii` did. [(#1119)](https://github.com/getsentry/sentry-symfony/pull/1119)

  If you already configure `data_collection`, it keeps applying as before and you only have to remove `send_default_pii` from your config. Otherwise, including when `data_collection` is `null`, restrict the collection to roughly what `send_default_pii: false` collected before with:

  ```yaml
  sentry:
      options:
          data_collection:
              user_info: false
              cookies:
                  mode: off
              http_bodies: [incomingRequest]
              gen_ai:
                  inputs: false
                  outputs: false
              database_query_data: false
              queues: false
  ```

- The `trace_ignore_status_codes` option now defaults to `[404]`, so transactions of requests that end in a `404` response are no longer sent, including those of matched routes. [(#1059)](https://github.com/getsentry/sentry-symfony/pull/1059)

- The options that the PHP SDK removed are no longer available under `sentry.options`: `enable_tracing`, `spotlight_url`, `enable_logs`, `enable_metrics`, `context_lines`, `max_value_length`, `attach_metric_code_locations` and `before_send_metrics`. A configuration that still contains one of them fails with an `Unrecognized option` error. Logs and metrics are always sent when their APIs or the `LogsHandler` are used, and the `spotlight` option accepts a boolean or the URL of the Spotlight sidecar. [(#1108)](https://github.com/getsentry/sentry-symfony/pull/1108), [(#1119)](https://github.com/getsentry/sentry-symfony/pull/1119)

- The `max_request_body_size` option no longer accepts `none`, use `never` instead. [(#1108)](https://github.com/getsentry/sentry-symfony/pull/1108)

- The listeners and the other services of the bundle take a `Sentry\SentryBundle\Sentry` instead of a `HubInterface` as constructor argument, and the deprecated constructor signatures of `SentryTestCommand`, `TracingDriverMiddleware` and the Twig `SentryExtension` have been removed. [(#1108)](https://github.com/getsentry/sentry-symfony/pull/1108)

- The `jean85/pretty-package-versions` dependency has been removed. The bundle reads the version of the application through `Composer\InstalledVersions`, which requires Composer 2. [(#1047)](https://github.com/getsentry/sentry-symfony/pull/1047)

### Features

- Support the global scope and the isolation scope of the PHP SDK. Data on the global scope is applied to every event of the process and survives across requests and messages in long-running workers, while the isolation scope holds the data of the current request, command or message. [(#1108)](https://github.com/getsentry/sentry-symfony/pull/1108)

  ```php
  // Applied to every event of this worker process
  \Sentry\getGlobalScope()->setTag('worker', 'queue-1');

  // Applied to the events of the current request, command or message
  \Sentry\getIsolationScope()->setTag('feature', 'checkout');
  ```

## 5.17.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.17.0.

### Features

- Resolve the user lazily for logs and metrics when a lazy firewall is used. [(#1143)](https://github.com/getsentry/sentry-symfony/pull/1143)

### Bug fixes

- Fix the session being started on every request to read the user when a lazy firewall is used, which locked the session until the response was sent. The user is now read when an event is captured. [(#1129)](https://github.com/getsentry/sentry-symfony/pull/1129)
- Fix the scope of the parent request being popped when a sub-request fails before its own scope is pushed, e.g. if no route matches. [(#1126)](https://github.com/getsentry/sentry-symfony/pull/1126)
- Fix the transaction being finished in the middle of the request when a sub-request fails before its span is started, e.g. if no route matches. [(#1125)](https://github.com/getsentry/sentry-symfony/pull/1125)

### Misc

- Bump `sentry-php` version to `4.38.0`. [(#1143)](https://github.com/getsentry/sentry-symfony/pull/1143)

## 5.16.1

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.16.1.

### Misc

- Exclude `AGENTS.md`, `CLAUDE.md` and `CONTRIBUTING.md` from the distributed package. [(#1140)](https://github.com/getsentry/sentry-symfony/pull/1140)

## 5.16.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.16.0.

### Features

- Add the `trace_ignore_status_codes` option, which drops the transactions of incoming requests whose response has one of the given HTTP status codes. Each entry is either a single status code or an inclusive `[min, max]` range. [(#1101)](https://github.com/getsentry/sentry-symfony/pull/1101)

  ```yaml
  sentry:
      options:
          trace_ignore_status_codes:
              - 404
              - [500, 599]
  ```

- Add the `sentry_sample_rate` env var processor, which resolves an empty or unset environment variable to `null`. [(#1096)](https://github.com/getsentry/sentry-symfony/pull/1096)

  ```yaml
  sentry:
      options:
          traces_sample_rate: '%env(sentry_sample_rate:SENTRY_TRACES_SAMPLE_RATE)%'
          profiles_sample_rate: '%env(sentry_sample_rate:SENTRY_PROFILES_SAMPLE_RATE)%'
  ```

### Bug fixes

- Fix `tracing.enabled` always enabling tracing when it is set through an environment variable such as `'%env(bool:SENTRY_TRACING)%'`. The value is now resolved at runtime. [(#1109)](https://github.com/getsentry/sentry-symfony/pull/1109)

### Misc

- Skip creating Doctrine DBAL spans when the parent span is not sampled, which reduces the overhead of unsampled requests. [(#1106)](https://github.com/getsentry/sentry-symfony/pull/1106)
- Add PHP 8.6 to the CI test matrix. [(#1107)](https://github.com/getsentry/sentry-symfony/pull/1107)
- Bump `sentry-php` version to `4.35.0`. [(#1101)](https://github.com/getsentry/sentry-symfony/pull/1101)

## 5.15.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.15.0.

### Features

- Add the experimental `register_monolog_handler` option, which captures errors through the Monolog channels Symfony reports them to instead of the SDK's global error handlers. [(#1092)](https://github.com/getsentry/sentry-symfony/pull/1092)

  ```yaml
  sentry:
      register_error_listener: false
      register_error_handler: false
      register_monolog_handler: true
  ```

- Add the `$includeChannel` argument to the Monolog `LogsHandler`, which attaches the Monolog channel as a `channel` attribute to logs. [(#1091)](https://github.com/getsentry/sentry-symfony/pull/1091)

  ```yaml
  services:
      Sentry\SentryBundle\Monolog\LogsHandler:
          arguments:
              $level: !php/const Monolog\Logger::WARNING
              $includeChannel: true
  ```

### Bug fixes

- Fix empty response bodies from the PSR-18 clients when HTTP client tracing is enabled on Symfony HttpClient 6.4.49, 7.4.22 and 8.1.10. [(#1099)](https://github.com/getsentry/sentry-symfony/pull/1099)

## 5.14.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.14.0.

### Features

- Add the `data_collection` option to control which potentially sensitive data the SDK attaches to events and spans. [(#1087)](https://github.com/getsentry/sentry-symfony/pull/1087)

  ```yaml
  sentry:
      options:
          data_collection:
              user_info: true
              http_headers:
                  mode: allowList
                  terms:
                      - x-request-id
              http_bodies: []
              database_query_data: false
  ```

### Bug fixes

- Set the user context before the router runs, so that events for unmatched routes include the client IP address. [(#1068)](https://github.com/getsentry/sentry-symfony/pull/1068)

### Misc

- Bump `sentry-php` version to `4.34.0`. [(#1087)](https://github.com/getsentry/sentry-symfony/pull/1087)

## 5.13.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.13.0.

### Misc

- Bump `sentry-php` version to `4.31.0`. [(#1053)](https://github.com/getsentry/sentry-symfony/pull/1053)

## 5.12.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.12.0.

### Bug fixes

- Start the runtime context before the router and security firewall to prevent logs and breadcrumbs from leaking across requests on persistent workers. [(#1043)](https://github.com/getsentry/sentry-symfony/pull/1043)

### Misc

- Add support for `guzzlehttp/psr7` 3.x for Guzzle 8 compatibility. [(#1046)](https://github.com/getsentry/sentry-symfony/pull/1046)

## 5.11.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.11.0.

### Bug fixes

- Escape values rendered by the Twig `sentry_trace_meta()` and `sentry_baggage_meta()` helpers. [(#1025)](https://github.com/getsentry/sentry-symfony/pull/1025)
- Clear the active request from `RequestFetcher` during kernel termination even when no transaction is set on the hub. [(#1026)](https://github.com/getsentry/sentry-symfony/pull/1026)
- Use Symfony's `security.untracked_token_storage` service in `LoginListener`. [(#1028)](https://github.com/getsentry/sentry-symfony/pull/1028)

## 5.10.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.10.0.

### Features

- Add support for `strict_trace_continuation` and `org_id` to continue incoming traces only when they belong to the same Sentry organization. [(#1002)](https://github.com/getsentry/sentry-symfony/pull/1002)
- Add `ConsoleInputSerializer` to capture console command arguments and options via `class_serializers`. [(#1007)](https://github.com/getsentry/sentry-symfony/pull/1007)
- Add support for `log_flush_threshold` to flush logs once a configured batch size is reached. [(#1012)](https://github.com/getsentry/sentry-symfony/pull/1012)
- Add support for `Sentry\Integration\OTLPIntegration`. [(#1014)](https://github.com/getsentry/sentry-symfony/pull/1014)
- Add `tracing.dbal.ignore_prepare_spans` to suppress `db.sql.prepare` spans for prepared queries. [(#1016)](https://github.com/getsentry/sentry-symfony/pull/1016)
- Isolate runtime context between HTTP requests on persistent workers, and add `messenger.isolate_context_by_message` to prevent scope, logs, and metrics data from leaking across messages. [(#997)](https://github.com/getsentry/sentry-symfony/pull/997)

### Bug fixes

- Remove the deprecated `traceparent` header. [(#1005)](https://github.com/getsentry/sentry-symfony/pull/1005)

### Misc

- Bump `sentry-php` version to `4.23.0`. [(#1012)](https://github.com/getsentry/sentry-symfony/pull/1012)

## 5.9.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.9.0.

### Misc

- Bump `sentry-php` version to `4.20.0`. [(#996)](https://github.com/getsentry/sentry-symfony/pull/996)

## 5.8.3

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.8.3.

### Bug fixes

- Using caches with namespaces will no longer cause an error. [(#986)](https://github.com/getsentry/sentry-symfony/pull/986)

## 5.8.2

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.8.2.

### Bug fixes

- Move `symfony/yaml` to a non-dev dependency. [(#983)](https://github.com/getsentry/sentry-symfony/pull/983)

## 5.8.1

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.8.1.

### Improvements

- Add support for `before_send_metric` and `enable_metrics`. [(#981)](https://github.com/getsentry/sentry-symfony/pull/981)

## 5.8.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.8.0.

### Features

- Add support for Sentry metrics. ([#977])(https://github.com/getsentry/sentry-symfony/pull/977)
```php
// Counter metric
\Sentry\trace_metrics()->count('test-counter', 10, ['my-attribute' => 'foo']);

// Gauge metric
\Sentry\trace_metrics()->gauge('test-gauge', 50.0, ['my-attribute' => 'foo'], \Sentry\Unit::millisecond());

// Distribution metric
\Sentry\trace_metrics()->distribution('test-distribution', 20.0, ['my-attribute' => 'foo'], \Sentry\Unit::kilobyte());
```

## 5.7.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.7.0.

### Features

- Add support for Symfony 8. [(#968)](https://github.com/getsentry/sentry-symfony/pull/968)

### Misc

- Add `never` as valid option for `max_request_body_size`. [(#966)](https://github.com/getsentry/sentry-symfony/pull/966)

## 5.6.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.6.0.

### Features

- Remove upper limit for `max_breadcrumbs` option. [(#953)](https://github.com/getsentry/sentry-symfony/pull/953)
- Allow passing string and enum Monolog level. [(#959)](https://github.com/getsentry/sentry-symfony/pull/959)
```yaml
services:
  Sentry\SentryBundle\Monolog\LogsHandler:
    arguments:
      - 'info'
```

```yaml
# or using PSR constants
services:
  Sentry\SentryBundle\Monolog\LogsHandler:
    arguments:
      - !php/const Psr\Log\LogLevel::INFO
```

```php
// or via PHP config
$container->services()
    ->set(\Sentry\SentryBundle\Monolog\LogsHandler::class)
    ->args([\Monolog\Level::Info]);
```

## 5.5.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.5.0.

### Features

- Enable auto-instrumentation for the Symfony Cache Component [(#942)](https://github.com/getsentry/sentry-symfony/pull/942)
- Add a new config flag to reset breadcrumbs between Symfony messages [(#946)](https://github.com/getsentry/sentry-symfony/pull/946)
```yaml
sentry:
  messenger:
    isolate_breadcrumbs_by_message: true
```
- Allow to pass in callbacks to configure SDK integrations [(#947)](https://github.com/getsentry/sentry-symfony/pull/947)
```yaml
services:
    App\IntegrationCallback:
        factory: ['App\IntegrationCallback', 'factory']

sentry:
    options:
        integrations: 'App\IntegrationCallback'
```

## 5.4.1

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.4.1.

### Bug Fixes

- Support named arguments in BufferFlushPass. [(#948)](https://github.com/getsentry/sentry-symfony/pull/948)

## 5.4.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.4.0.

### Features

- Introduce Sentry Structured Logging support. [(#940)](https://github.com/getsentry/sentry-symfony/pull/940)

```yaml
sentry:
  options:
    enable_logs: true

services:
  Sentry\SentryBundle\Monolog\LogsHandler:
    arguments:
      - !php/const Monolog\Logger::INFO

monolog:
  handlers:
    sentry_logs:
      type: service
      id: Sentry\SentryBundle\Monolog\LogsHandler
```

### Bug Fixes

- Add buffer flusher for sentry monolog handler. [(#936)](https://github.com/getsentry/sentry-symfony/pull/936)

## 5.3.1

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.3.1.

### Bug Fixes

- Fix exception thrown when invalid IP address was captured by the request listener [(#931)](https://github.com/getsentry/sentry-symfony/pull/931)

## 5.3.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.3.0.

### Features

- Implement `NamespacedPoolInterface` for `TraceableCacheAdapterForV3` [(#927)](https://github.com/getsentry/sentry-symfony/pull/927)

### Misc

- Update minimum required version of `sentry/sentry` to `^4.14.1`
- Remove support for `traceparent` header [(#928)](https://github.com/getsentry/sentry-symfony/pull/928)

## 5.2.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.2.0.

### Features

- Allow to configure the logger via the `sentry.yaml` configuration file [(#899)](https://github.com/getsentry/sentry-symfony/pull/899)

  ```yaml
  sentry:
    dsn: "%env(SENTRY_DSN)%"
    options:
        logger: "sentry.logger"

  services:
    sentry.logger:
      class: 'Sentry\Logger\DebugFileLogger'
      arguments:
          $filePath: '../../var/log/sentry.log'
  ```

### Bug Fixes

- Fixed updating the user context when a route is marked as stateless [(#910)](https://github.com/getsentry/sentry-symfony/pull/910)

### Misc

- Remove `symfony/security-core` and `symfony/security-http` as dependencies [(#912)](https://github.com/getsentry/sentry-symfony/pull/912)

## 5.1.0

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.1.0.

### Features

- The SDK was updated to support PHP 8.4 [(#893)](https://github.com/getsentry/sentry-symfony/pull/893)
- Set the status for CLI command transactions based on the exit code [(#891)](https://github.com/getsentry/sentry-symfony/pull/891)

### Bug Fixes

- Fix including request data on transactions  [(#879)](https://github.com/getsentry/sentry-symfony/pull/879)

## 5.0.1

The Sentry SDK team is happy to announce the immediate availability of Sentry Symfony SDK v5.0.1.

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
