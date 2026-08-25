# Implementation Standard

## BITS Groups.io Membership Sync

### 1. Purpose

This document settles the implementation-level decisions that the PRD, Security document, and Testing Standard deliberately deferred to it, plus general tooling, dependency management, and review conventions.

### 2. Tooling

* Dependency management: Composer. **`composer.lock` is deliberately not committed** — a lock file generated on a contributor's newer local PHP version has previously pinned dependency versions incompatible with CI's PHP 8.0 matrix, breaking CI in a way that wasn't visible from the diff. `composer.lock` is fine to exist locally (and does, for whichever PHP version a contributor's own machine runs); it must simply never be `git add`ed. Reproducibility across environments instead comes from the version constraints in `composer.json` plus CI always installing fresh against its own pinned PHP 8.0 matrix.
* Linting: PHP_CodeSniffer with the WordPress Coding Standards (WPCS) ruleset, run locally and in CI.
* Static analysis: PHPStan (level to be set once the codebase exists and a realistic baseline can be established — starting stricter and loosening only with justification, not the reverse).
* The Action Scheduler library is included as a Composer dependency (`woocommerce/action-scheduler`) rather than copy-pasted, so it can be updated via normal dependency management.

### 3. Constants-Override-Options Pattern

Resolved (deferred from PRD section 3.2): for the database-backed operational settings (mandatory groups, grace period, log retention, kill switch, mass-action anomaly threshold), a `wp-config.php` constant may optionally override the stored option value — checked first, falling back to the stored option if the constant is undefined. This gives ops an emergency override path (e.g., force the kill switch on via a constant if the admin UI is somehow unreachable) without changing the primary configuration mechanism.

### 4. Rate Limiting Thresholds

Resolved (deferred from the Security document, section 5):

* Magic link request form: rate limited per email address and per requesting IP address, on a rolling hourly window. Exceeding either limit returns the same generic response as a normal request (per the Security document's enumeration mitigation) but does not actually send another email.
* The exact threshold values are an operational security parameter and are not published in this document (see the project's private security addendum, maintained outside this repository). They are admin-configurable operational settings (added to PRD section 3.2), not hardcoded, since real-world tuning may be needed after launch.

### 5. Mass-Action Anomaly Threshold Default

Resolved (deferred from the Security document, section 4): a default job-count-within-a-rolling-time-window threshold triggers the circuit breaker described in the Security document's Threat Model. The exact default values are an operational security parameter and are not published in this document (see the project's private security addendum, maintained outside this repository). This is an admin-configurable operational setting (PRD section 3.2), and should be reviewed against BITS's actual membership size once known, since the right threshold depends on how large a "normal" burst (e.g., several members renewing around the same billing date) could plausibly be.

### 6. Test Credential Rotation Cadence

Resolved (deferred from the Security document, section 6): the test-group Groups.io API key is rotated on a defined cadence (see the project's private security addendum), and immediately upon any suspected exposure.

### 7. Reconciliation Dry-Run Mode

Resolved (deferred from the Security document, section 4): the nightly reconciliation job supports a dry-run mode (computing and logging the delta it would act on, without executing any add/remove calls), controlled by an admin-configurable setting. Dry-run mode is the default immediately after Phase 6 is first deployed to the live site, and is switched to live execution only after an admin reviews at least one dry-run report and confirms it looks correct — this is a deployment/rollout step to be reflected in the Definition of Done for Phase 6, not an automated code behavior.

### 8. Review Expectations

* Every pull request requires review and all review threads resolved before merging, per `CLAUDE.md`'s branching strategy.
* A pull request touching credential handling, the magic link, or GDPR/audit-retention logic should call that out explicitly in its description, since those are the areas the Security document's checklist applies most directly to.

### 9. Test-Execution Mechanics

* Local: `composer test` runs the full unit PHPUnit suite (`vendor/bin/phpunit`). The integration suite runs separately via `vendor/bin/phpunit -c phpunit.integration.xml.dist`, requiring local environment variables for the test credential. See `CONTRIBUTING.md`'s Local Development Setup section for the one-time environment setup this depends on (WordPress core test suite bootstrap, a MySQL/MariaDB client for `install-wp-tests.sh`, `wp-env`'s dynamically-assigned MySQL port).
* `composer test-coverage` runs the same suite with coverage instrumentation (`XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text --coverage-clover=coverage.xml`), matching CI's own invocation exactly so the 80% gate (below) can be checked locally before pushing. Requires a coverage driver (Xdebug or PCOV) installed for the local PHP CLI — not installed by default.
* **Changed 2026-08-11**: CI automatically runs the unit suite and the Playwright e2e suite on every pull request, per the Testing Standard document; coverage is computed from the unit suite only and gated at 80%. The integration suite no longer runs automatically — it runs only via manual dispatch of the `integration.yml` workflow (per `docs/ci.md` section 3.3), and a contributor touching Groups.io-calling code is expected to trigger it manually before requesting merge approval.

### 10. Branch Naming and Changelog Discipline

* Every branch cut from `dev` uses one of four prefixes: `feature/*` (feature work), `bug/*` (bug fixes), `docs/*` (documentation-only changes), `infra/*` (tooling, CI, and other infrastructure work that is not itself part of the plugin's shipped behavior). CI (`branch-name-lint`) validates this on every pull request.
* `CHANGELOG.md` at the `app/` repository root (`Keep a Changelog` format) is the source of truth for version history. Every pull request from a `feature/*` or `bug/*` branch must update its `## [Unreleased]` section under the appropriate subheading; CI (`changelog-check`) enforces this. `infra/*` and `docs/*` branches are exempt (see `ci.md` section 4 for the full release process this feeds).
