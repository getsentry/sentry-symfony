# Upgrade 5.x to 6.0

This version adds support for the underlying [Sentry PHP SDK v5.0](https://github.com/getsentry/sentry-php).
Please refer to the PHP SDK [sentry-php/UPGRADE-5.0.md](https://github.com/getsentry/sentry-php/blob/5.x/UPGRADE-5.0.md) guide for a complete list of breaking changes.

- Composer 2 is required. The bundle reads the version of your application through the Composer runtime API, and `jean85/pretty-package-versions` is no longer a dependency of the bundle. If your code relies on that package, require it in your own `composer.json`.

- The PHP SDK no longer has hubs, so the `Sentry\State\HubInterface` service was removed. Use the functions of the SDK instead, which work on the scopes of the current request, command or message:

  ```php
  // Before
  public function __construct(private HubInterface $hub)
  {
  }

  public function __invoke(): void
  {
      $this->hub->configureScope(static function (Scope $scope): void {
          $scope->setTag('feature', 'checkout');
      });

      $this->hub->captureException($exception);
  }

  // After
  public function __invoke(): void
  {
      \Sentry\configureScope(static function (\Sentry\State\IsolationScope $scope): void {
          $scope->setTag('feature', 'checkout');
      });

      \Sentry\captureException($exception);
  }
  ```

  The `Sentry\ClientInterface` service is still available if you need the client.

- `Sentry\State\Scope` is now an abstract class without setters. The callbacks of `\Sentry\configureScope()` receive a `Sentry\State\IsolationScope`, so update their type declarations. The `\Sentry\withScope()` function was removed, use `\Sentry\withIsolationScope()` instead.

- The scope of a request now starts empty. Data that is set with `\Sentry\configureScope()` before the request is handled, for example in `Kernel::boot()`, in the `boot()` method of a bundle or in the front controller, is no longer part of the events of that request. The same applies to the messages of a Messenger worker when the `sentry.messenger.isolate_context_by_message` option is enabled. Set the data that applies to every request or message on the global scope instead:

  ```php
  // Before
  \Sentry\configureScope(static function (\Sentry\State\Scope $scope): void {
      $scope->setTag('region', 'eu-west-1');
  });

  // After
  \Sentry\getGlobalScope()->setTag('region', 'eu-west-1');
  ```

- The PHP SDK removed the `Sentry\Monolog\Handler` class. Use the `Sentry\Monolog\LogsHandler` handler to send the records as Sentry logs, or the `Sentry\Monolog\LogToSentryIssueHandler` and `Sentry\Monolog\ExceptionToSentryIssueHandler` handlers to send them as Sentry issues. The constructors of these handlers and of `Sentry\Monolog\BreadcrumbHandler` no longer take a hub:

  ```yaml
  // config/packages/sentry.yaml

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

  The `sentry` handler type of the MonologBundle creates the removed `Sentry\Monolog\Handler` class, use the `service` handler type instead.

- `buffer` handlers of the MonologBundle are now flushed before the scope of the request or command ends when they wrap a `Sentry\Monolog\LogToSentryIssueHandler` or `Sentry\Monolog\ExceptionToSentryIssueHandler` handler.

- Removed the `sentry.options.send_default_pii` option and the legacy data collection it controlled. The `sentry.options.data_collection` option now always decides which data is collected automatically, and a `null` value applies the default of every option instead of keeping the legacy options.

  If you already set `sentry.options.data_collection` to something other than `null`, `send_default_pii` was already ignored and your configuration keeps applying. You only have to remove `send_default_pii` from your configuration. The rest of this section is for applications where `data_collection` is missing or `null`.

  The defaults collect more data than `send_default_pii` did, with either value:

  - The IP address of the user and the user of the security token are set on the events, and the IP address is added to the transaction of the request.
  - Cookies, HTTP headers, URL query parameters and function arguments in stack frames are collected, and values whose name matches the built-in list of sensitive terms are replaced with `[Filtered]`. The same applies to the arguments and options in the `Full command` extra of console commands.
  - HTTP headers, cookies and bodies of the request and its response are attached to the transaction, and those of the requests sent with the HTTP client to their spans, along with the full URL.
  - The parameters of database queries are attached to the spans of the DBAL tracing.

  To restrict the collection to roughly what `send_default_pii: false` collected before, opt out of these categories:

  ```yaml
  // config/packages/sentry.yaml

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

  The following changed along with it:

  - The values in request bodies are filtered like other collected data, bodies that cannot be parsed are sent as `[Filtered]`, and uploaded files are no longer added to the request data. The `always` value of the `sentry.options.max_request_body_size` option collects bodies up to 100,000 bytes instead of bodies of any size.
  - The `X-Forwarded-For` and `X-Real-IP` request headers are no longer filtered by default, and the `Cookie` and `Set-Cookie` headers are no longer part of the collected headers. To filter additional headers, add terms to the deny list, a header is filtered if its name contains one of the terms:

    ```yaml
    // config/packages/sentry.yaml

    sentry:
      options:
        data_collection:
          http_headers:
            request:
              mode: denyList
              terms: [x-tenant-id]
    ```

- The options below were removed from the configuration of the bundle, together with their attributes in the XML configuration. A configuration that still contains one of them fails when the container is built, with an `Unrecognized option` error for a removed option. The Flex recipe puts the configuration under `when@prod`, so make sure to build the container of that environment after the upgrade, for example with `php bin/console lint:container --env=prod`.

- Removed the `sentry.options.context_lines` option. Use the `sentry.options.data_collection.frame_context_lines` option instead.
- Removed the `sentry.options.enable_logs` and `sentry.options.enable_metrics` options. Logs and metrics are sent whenever their APIs or handlers are used. To stop sending them, return `null` from the `sentry.options.before_send_log` or `sentry.options.before_send_metric` callback.
- Removed the `sentry.options.enable_tracing` option. Use the `sentry.options.traces_sample_rate` or `sentry.options.traces_sampler` options instead.
- Removed the `sentry.options.attach_metric_code_locations` option.
- Removed the `sentry.options.before_send_metrics` option. Use the `sentry.options.before_send_metric` option to filter the metrics sent with `\Sentry\metrics()`.
- Removed the `sentry.options.max_value_length` option, as the PHP SDK no longer truncates the data of the events.
- Removed the `sentry.options.spotlight_url` option. Set the URL of the Spotlight sidecar as value of the `sentry.options.spotlight` option instead, which now accepts a boolean or a URL. A URL with a path is used as it is, `/stream` is only appended to a URL without a path such as `http://localhost:8969`.
- Removed the `none` value of the `sentry.options.max_request_body_size` option. Use `never` instead.

- The `sentry.options.trace_ignore_status_codes` option now defaults to `[404]`, so the transactions of requests that end in a `404` response are no longer sent. This also covers matched routes that respond with a 404, for example when a controller throws a `NotFoundHttpException`. Set the option to an empty list to keep sending them:

  ```yaml
  // config/packages/sentry.yaml

  sentry:
    options:
      trace_ignore_status_codes: []
  ```

- Error events get the name of the active transaction as their `transaction`, for example the one of the request or the console command, and otherwise the path of the request URL. Before, they only had a `transaction` when the `PATH_INFO` server variable was set.
- The span that was active when a log was recorded is sent as the `span_id` of the log instead of the `sentry.trace.parent_span_id` attribute. `sentry.options.before_send_log` callbacks that read the attribute have to use `Log::getSpanId()`, and searches or alerts in Sentry that filter on the attribute have to be changed.
- The `Sentry\SentryBundle\Monolog\LogsHandler` and `Sentry\Monolog\LogsHandler` handlers replace PSR-3 placeholders such as `{user}` with the matching values of the record context, so the body of the log changes from the template to the interpolated message. The template is sent as the `sentry.message.template` attribute and the values as `sentry.message.parameter.<name>` attributes.

- The services of the bundle take an instance of `Sentry\SentryBundle\Sentry` instead of `Sentry\State\HubInterface` as constructor argument. This applies to the listeners in the `Sentry\SentryBundle\EventListener` namespace, to `Sentry\SentryBundle\Tracing\Twig\TwigTracingExtension` and to `Sentry\SentryBundle\Command\SentryTestCommand`, where the argument is no longer optional. If you register or decorate one of these classes yourself, pass the `Sentry\SentryBundle\Sentry` service to it. The class is internal, do not rely on it in your own code.
- The constructor of `Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverMiddleware` now requires an instance of `Sentry\SentryBundle\Tracing\Doctrine\DBAL\TracingDriverConnectionFactoryInterface`.
- The constructor of `Sentry\SentryBundle\Twig\SentryExtension` no longer accepts a hub.

- The [PHP SDK upgrade guide](https://github.com/getsentry/sentry-php/blob/5.x/UPGRADE-5.0.md) has the complete list of the changes to the APIs of the PHP SDK. These are the ones Symfony applications run into most often:

  - `SentrySdk::getCurrentHub()` and `Sentry\State\HubAdapter` were removed. Use the `\Sentry\...()` functions or `SentrySdk::getClient()`.
  - `getClient()` never returns `null`. When no client is configured it returns a `Sentry\NoOpClient`.
  - `Scope::addGlobalEventProcessor()` was removed. Use `SentrySdk::getGlobalScope()->addEventProcessor()`. Event processors only run for error events and transactions, no longer for check-ins, logs and metrics. Use the `sentry.options.before_send_check_in`, `sentry.options.before_send_log` and `sentry.options.before_send_metric` options for those.
  - `\Sentry\traceMetrics()` was removed. Use `\Sentry\metrics()`, which returns the class that was called `TraceMetrics` before. The old Metrics API it returned before was removed.
  - `\Sentry\getW3CTraceparent()` was removed. Use `\Sentry\getTraceparent()`.
  - The `segment` of the user was removed. A `segment` key passed to `setUser()` is stored as user metadata instead, use a tag or a context for it.
