# AGENTS.md

## Overview

- `sentry/sentry-symfony` is a Composer library and Symfony bundle, not an
  application.
- The bundle wires the Sentry PHP SDK into Symfony and optional integrations
  such as request handling, console, Messenger, Twig, Cache, DBAL, HTTP
  client, metrics, and Monolog/logging.
- The main entry points are:
  - `src/SentryBundle.php`: registers compiler passes.
  - `src/DependencyInjection/Configuration.php`: defines the config tree.
  - `src/DependencyInjection/SentryExtension.php`: turns config into container
    definitions and parameters.
  - `src/Resources/config/services.yaml`: defines base services and listener
    tags.
  - `src/aliases.php`: selects version-specific classes by runtime feature
    detection.

## Compatibility Rules

- The minimum supported PHP version for shipped code is `7.2`.
  `composer.json` requires `^7.2 || ^8.0`, so code added to this package must
  remain valid on PHP `7.2` unless support policy is intentionally being
  changed.
- CI exercises Symfony `4.4` through `8`, so Symfony-facing changes must keep
  the existing cross-version behavior intact.
- Do not assume optional packages are installed. Messenger, Doctrine, Twig,
  Cache, and HttpClient support are guarded throughout the codebase with
  `class_exists()`, `interface_exists()`, and `method_exists()`.
- Preserve the existing compatibility style. Prefer feature detection and
  version-specific implementation classes over hard version checks when
  extending support for Symfony or Doctrine.
- If you change tracing wrappers or compatibility behavior, verify the matching
  aliases in `src/aliases.php` and the version-specific files under
  `src/Tracing/`.
- Listener priorities are intentional and covered by tests. Changing listener
  order usually requires test updates and may require an upgrade note.

## Editing Guidance

- Keep `declare(strict_types=1);` in PHP files.
- Do not introduce PHP syntax or runtime assumptions that require newer than
  PHP `7.2` in shipped code unless the package minimum is being raised.
- Follow the existing formatting rules from `.php-cs-fixer.dist.php`: Symfony
  style, short arrays, ordered imports, yoda conditions, and 4-space
  indentation.
- Services are private by default. Prefer existing FQCN service IDs and aliases
  instead of introducing new public services.
- If a service or class has no intended public API surface but is public only
  for tests, wiring, or framework integration reasons, mark it `@internal` so
  users do not rely on it as a stable extension point and future refactors do
  not become accidental BC breaks.
- Config changes usually require synchronized updates in all of these places:
  - `src/DependencyInjection/Configuration.php`
  - `src/DependencyInjection/SentryExtension.php`
  - `src/Resources/config/services.yaml`
  - `src/Resources/config/schema/sentry-1.0.xsd`
  - `tests/DependencyInjection/Fixtures/{yml,php,xml}`
  - `tests/DependencyInjection/ConfigurationTest.php`
  - `tests/DependencyInjection/SentryExtensionTest.php`
- XML fixtures are still relevant for older Symfony versions even though XML
  config support is skipped on Symfony 8+ in tests.
- End-to-end behavior lives in `tests/End2End/App`. Prefer end-to-end coverage
  for regressions involving listeners, request scope data, tracing, logging,
  metrics, or console behavior.
- DBAL compatibility tests use helpers in `tests/DoctrineTestCase.php`. Keep
  skip conditions and version-specific expectations intact.
- `tests/bootstrap.php` registers `ClockMock` for tracing timing behavior. Do
  not remove timing assumptions without updating the related tests.
- `src/EventListener/TracingRequestListener.php` temporarily injects the active
  request into `RequestFetcher` and clears it on terminate. Keep that set/reset
  lifecycle balanced if you change request tracing.
- `BufferFlushPass`, logging, and metrics code are sensitive to terminate-time
  behavior. Add end-to-end coverage when changing those paths.

## Test Expectations

- Add tests with every behavior change. This is a library repo and the existing
  test suite is broad.
- For config changes, update the YAML, PHP, and XML DI fixtures together.
- For optional-package behavior, mirror the existing pattern of conditional
  skips instead of forcing packages to exist in every environment.
- Local verification commands:
  - `composer install`
  - `composer cs-check`
  - `composer phpstan`
  - `composer psalm`
  - `composer tests`
  - `composer check`
- `composer phpstan` runs PHPStan with `512M` because the default local PHP
  memory limit can be too low for this repo.
- `php-cs-fixer` is the formatting tool in this repo. If formatting fails, run
  `composer cs-fix` and then rerun `composer check`.
- Before committing, run `composer check`.

## Docs And Release Notes

- Update `README.md` when public installation, configuration, or usage changes.
- Update `CHANGELOG.md` for user-facing changes.
- Add or update an `UPGRADE-*.md` file when a change is breaking or alters the
  upgrade path.
- `CONTRIBUTING.md` states that new code should include tests and notes that
  style checkers require PHP 7.4+ locally, but shipped code still has to comply
  with the package's PHP `7.2` baseline.

## CI Notes

- `.github/workflows/tests.yaml` runs a PHP/Symfony matrix and also includes
  jobs that remove optional packages before running tests.
- `.github/workflows/static-analysis.yaml` runs PHP-CS-Fixer, PHPStan, and
  Psalm on a recent PHP version. Check the workflow file for the exact version
  currently used in CI.
- A single local green run is necessary but not sufficient. Avoid changes that
  only work when optional packages are present.
