# Contributing

Thank you for your interest in contributing to the BITS Groups.io Membership Sync plugin.

## Before You Start

* This project follows a strict Plan → Design → Track → Implement → Test order of operations. No pull request will be accepted for work that skipped design confirmation or doesn't correspond to a tracked GitHub issue with complete acceptance criteria.
* Check open issues before starting work, to avoid duplicate effort. If no issue exists for what you want to work on, open one first and wait for it to be scoped and confirmed before writing code.

## Branching

* `dev` is the integration branch; `main` is the release branch. Cut your branch from `dev`, not `main`.
* Both `dev` and `main` are protected: no direct pushes, no force pushes, no branch deletion, administrators are not exempt.
* Branch names use one of four prefixes, checked by CI:
  * `feature/*` — new functionality.
  * `bug/*` — bug fixes.
  * `docs/*` — documentation-only changes.
  * `infra/*` — tooling, CI, and other infrastructure work that isn't itself part of the plugin's shipped behavior.

## Local Development Setup

Prerequisites:

* PHP 8.0 or newer, with the `dom`, `simplexml`, `xml`, `mbstring`, `mysql`, and `zip` extensions.
* [Composer](https://getcomposer.org/).
* Node.js and npm (for `wp-env` and the Playwright e2e suite).
* Docker and Docker Compose (`wp-env` runs a local WordPress site in containers).
* A MySQL/MariaDB **client** binary (`mysqladmin`) on the machine running the tests — see the note below. On Debian/Ubuntu this is the `mariadb-client` package; it is not installed by default on a minimal server image and is easy to miss.

Setup steps:

1. `npm install` — installs `@wordpress/env` and `@playwright/test`, this repo's only JS dependencies (the plugin itself has no JS runtime dependency).
2. `composer install` — installs PHP dependencies. **`composer.lock` is intentionally not committed to this repo** (see `docs/implementation-standard.md` section 2): a committed lock file generated on a newer local PHP version has previously pinned dependency versions incompatible with CI's PHP 8.0 matrix, breaking CI for reasons invisible from the diff. It is fine — expected, even — for `composer.lock` to exist locally; just never `git add` it.
3. `npx wp-env start` — brings up the Docker-based WordPress dev site at `http://localhost:8888` (configurable via `.wp-env.json`'s `port`). The plugin is auto-mounted and auto-activated. `wp-env`'s own separate built-in test environment (`testsEnvironment` in `.wp-env.json`, normally on port 8889) is **disabled and not used** for this project — the PHPUnit test suite (below) runs against `wp-env`'s dev-site MySQL container instead, via `bin/install-wp-tests.sh`.
4. Run `composer test` once. The first run installs the WordPress core PHPUnit test suite via `bin/install-wp-tests.sh` into `/tmp/wordpress-tests-lib`/`/tmp/wordpress`, and requires a MySQL/MariaDB **client** to actually create the `wordpress_test` database as part of that install:
   * **If `mysqladmin` isn't installed**, `install-wp-tests.sh`'s `mysqladmin create` step fails, but the script does not error loudly or stop — the database simply never gets created, and the only symptom is a much later, confusing WordPress `wp_die()` "Cannot select database" failure when `composer test` actually runs. Install the client first (`sudo apt install mariadb-client` on Debian/Ubuntu) if you hit this.
   * `wp-env`'s MySQL host port is dynamically assigned by Docker and **can change** whenever `wp-env`'s containers are recreated (e.g. after editing `.wp-env.json` and re-running `npx wp-env start`, or after `npx wp-env destroy`). Find the current port with `docker ps` (look for the `...-mysql-1` container's forwarded port) and update `/tmp/wordpress-tests-lib/wp-tests-config.php`'s `DB_HOST` constant to match if `composer test` suddenly starts failing with a database connection error after previously working.
   * Export `WP_TESTS_DIR=/tmp/wordpress-tests-lib` in your shell profile so it doesn't need to be re-exported every session (the `sys_get_temp_dir()`-based default in `tests/bootstrap.php` happens to match, but setting it explicitly avoids relying on that coincidence).
5. For code coverage locally: install a coverage driver (Xdebug or PCOV) for your PHP CLI — e.g. `sudo apt install php8.4-xdebug` (adjust the version to match your PHP CLI). Xdebug adds overhead to every PHP CLI invocation unless its mode is explicitly scoped down, so set `xdebug.mode=off` as the default in its ini file and let `composer test-coverage` (which sets `XDEBUG_MODE=coverage` for just that one run) opt back in only when actually measuring coverage.
6. For the e2e suite: `npx playwright install --with-deps chromium` once (downloads the browser binary and any missing OS-level dependencies — a large download, but only needed once per machine), then `npx playwright test`.

## Making a Change

1. Confirm a GitHub issue exists for your change, with complete acceptance criteria (Given/When/Then for features, including at least one accessibility-focused scenario; Steps to Reproduce/Expected/Actual/Given-When-Then for bugs).
2. Create a branch from `dev` using the appropriate prefix above.
3. Write your code, following the project's linting (PHP_CodeSniffer/WPCS) and static analysis (PHPStan) configuration.
4. Write the corresponding unit test and, where applicable, integration test. If your change touches `GroupsIoApiClient` or another Groups.io-calling code path, manually trigger the `integration.yml` workflow against your branch and confirm it passes before requesting merge approval — the integration suite makes real calls to a test Groups.io group and no longer runs automatically on pull requests.
5. If your branch is `feature/*` or `bug/*`, add an entry to `CHANGELOG.md`'s `## [Unreleased]` section under the matching subheading (`### Added`/`### Changed` for features, `### Fixed`/`### Security` for bug fixes). `docs/*` and `infra/*` branches are exempt — this isn't user-facing plugin behavior.
6. Open a pull request against `dev`. CI must pass in full: documentation lint, code lint, static analysis, unit tests, the coverage gate, the changelog check, and the branch-name check.
7. If your change touches a UI surface, include a note confirming screen reader verification (see the project's accessibility standard).
8. If your change touches stored content, credentials, authentication, or the Groups.io API, note that it has been checked against the project's security document.

## Accessibility

This project is built by and for a blindness-focused nonprofit. Every UI surface must be fully navigable with a screen reader and meet WCAG 2.1 Level AA. This is not optional and not a follow-up task — a pull request introducing an inaccessible UI surface will not be merged as-is.

## Code of Conduct

By participating in this project, you agree to abide by the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

By contributing, you agree that your contributions will be licensed under the project's Apache License 2.0 (see `LICENSE` at the repository root).
