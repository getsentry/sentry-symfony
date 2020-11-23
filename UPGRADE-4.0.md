# Upgrade 3.x to 4.0

- Added the `$hub` argument to the constructor of the `SubRequestListener` class.
- Renamed the `ConsoleListener` class to `ConsoleCommandListener`.
- Removed the `ConsoleListener::onConsoleCommand` method.
- Renamed the `ErrorListener::onException` method to `ErrorListener::handleExceptionEvent`.
- Removed the `ErrorListener::onKernelException` method.
- Removed the `ErrorListener::onConsoleError` method.
- Renamed the `RequestListener::onKernelRequest` method to `RequestListener::handleKernelRequestEvent`.
- Renamed the `RequestListener::onKernelController` method to `RequestListener::handleKernelControllerEvent`.
- Renamed the `SubRequestListener::onKernelRequest` method to `SubRequestListener::handleKernelRequestEvent`.
- Renamed the `SubRequestListener::onKernelFinishRequest` method to `SubRequestListener::handleKernelFinishRequestEvent`.
- Removed the `sentry.options.excluded_exceptions` configuration option.
- Removed the `sentry.listener_priorities.console` configuration option.

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
