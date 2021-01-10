# Upgrade 3.x to 4.0

- Added the `$hub` argument to the constructor of the `SubRequestListener` class.
- Renamed the `ConsoleListener` class to `ConsoleCommandListener`.
- Renamed the `ConsoleListener::onConsoleCommand` method to `ConsoleCommandListener::handleConsoleCommandEvent`.
- Renamed the `ErrorListener::onException` method to `ErrorListener::handleExceptionEvent`.
- Removed the `ErrorListener::onKernelException` method.
- Removed the `ErrorListener::onConsoleError` method.
- Renamed the `RequestListener::onKernelRequest` method to `RequestListener::handleKernelRequestEvent`.
- Renamed the `RequestListener::onKernelController` method to `RequestListener::handleKernelControllerEvent`.
- Renamed the `SubRequestListener::onKernelRequest` method to `SubRequestListener::handleKernelRequestEvent`.
- Renamed the `SubRequestListener::onKernelFinishRequest` method to `SubRequestListener::handleKernelFinishRequestEvent`.
- Removed the `Sentry\FlushableClientInterface` service alias.
- Removed the `sentry.listener_priorities` configuration option.
- Removed the `sentry.options.excluded_exceptions` configuration option.

  Before:

  ```yaml
  sentry:
      options:
          excluded_exceptions:
              - RuntimeException
  ```

  After:

  ```yaml
  sentry:
      options:
          integrations:
              - '@Sentry\Integration\IgnoreErrorsIntegration'
  
  services:
      Sentry\Integration\IgnoreErrorsIntegration:
          arguments:
              $options:
                  ignore_exceptions:
                      - RuntimeException
  ```

- Changed the priority of the `ConsoleCommandListener::handleConsoleErrorEvent` listener to `-64`.
- Changed the priority of the `ConsoleCommandListener::::handleConsoleCommandEvent` listener to `128`.
- Changed the priority of the `MessengerListener::handleWorkerMessageFailedEvent` listener to `50`.
- Changed the type of the `sentry.options.before_send` configuration option from `scalar` to `string`. The value must always be the name of the container service to call without the `@` prefix.

  Before

  ```yaml
  sentry:
      options:
          before_send: '@app.sentry.before_send'
  ```

  ```yaml
  sentry:
      options:
          before_send: 'App\Sentry\BeforeSend::__invoke'
  ```

  ```yaml
  sentry:
      options:
          before_send: ['App\Sentry\BeforeSend', '__invoke']
  ```

  After

  ```yaml
  sentry:
      options:
          before_send: 'app.sentry.before_send'
  ```

- Changed the type of the `sentry.options.before_breadcrumb` configuration option from `scalar` to `string`. The value must always be the name of the container service to call without the `@` prefix.

  Before

  ```yaml
  sentry:
      options:
          before_breadcrumb: '@app.sentry.before_breadcrumb'
  ```

  ```yaml
  sentry:
      options:
          before_breadcrumb: 'App\Sentry\BeforeBreadcrumb::__invoke'
  ```

  ```yaml
  sentry:
      options:
          before_breadcrumb: ['App\Sentry\BeforeBreadcrumb', '__invoke']
  ```

  After

  ```yaml
  sentry:
      options:
          before_send: 'app.sentry.before_breadcrumb'
  ```

- Changed the type of the `sentry.options.class_serializers` configuration option from an array of `scalar` values to an array of `string` values. The value must always be the name of the container service to call without the `@` prefix.

  Before

  ```yaml
  sentry:
      options:
          class_serializers:
              App\FooClass: '@app.sentry.foo_class_serializer'
  ```

  ```yaml
  sentry:
      options:
          class_serializers:
              App\FooClass: 'App\Sentry\FooClassSerializer::__invoke'
  ```

  ```yaml
  sentry:
      options:
          class_serializers:
              App\FooClass: ['App\Sentry\FooClassSerializer', 'invoke']
  ```

  After

  ```yaml
  sentry:
      options:
          class_serializers:
              App\FooClass: 'app.sentry.foo_class_serializer'
  ```

- Changed the type of the `sentry.options.integrations` configuration option from an array of `scalar` values to an array of `string` values. The value must always be the name of the container service to call without the `@` prefix.

  Before

  ```yaml
  sentry:
      options:
          integrations:
              - '@app.sentry.foo_integration'
  ```

  After

  ```yaml
  sentry:
      options:
          integrations:
              - 'app.sentry.foo_integration'
  ```

- Removed the `ClientBuilderConfigurator` class.
- Removed the `SentryBundle::getSdkVersion()` method.
- Removed the `SentryBundle::getCurrentHub()` method, use `SentrySdk::getCurrentHub()` instead.
- Removed the `Sentry\ClientBuilderInterface` and `Sentry\Options` services.
- Refactorized the `ErrorTypesParser` class and made it `@internal`.
- Removed the `sentry.monolog` configuration option.

  Before

  ```yaml
  sentry:
      monolog:
          level: !php/const Monolog\Logger::ERROR
          bubble: false
          error_handler:
              enabled: true
  ```

  After

  ```yaml
  services:
      Sentry\Monolog\Handler:
          arguments:
              $hub: Sentry\State\HubInterface
              $level: !php/const Monolog\Logger::ERROR
              $bubble: false
  ```
