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
- Removed the `sentry.listener_priorities.console` configuration option.
- Removed the `Sentry\FlushableClientInterface` service alias.
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
      integrations:
          - '@Sentry\Integration\IgnoreErrorsIntegration'
  
  services:
      Sentry\Integration\IgnoreErrorsIntegration:
          arguments:
              $options:
                  ignore_exceptions:
                      - RuntimeException
  ```

- Changed the default value of the `sentry.listener_priorities.console_error` configuration option to `-64`.
- Changed the default value of the `sentry.listener_priorities.console` configuration option to `128`.
