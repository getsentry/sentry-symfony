# Upgrade 3.x to 4.0

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
