# Testing Standard

## BITS Groups.io Membership Sync

### 1. Purpose

This document specifies how testing works for this project: framework, environments, what gets mocked versus what hits real infrastructure, coverage enforcement, and naming conventions. It implements the testing-related commitments already made in `CLAUDE.md` and the Security document.

### 2. Frameworks and Environments

* **Unit and integration test framework**: PHPUnit, run against the WordPress core PHPUnit test suite bootstrap (via `wp-env`'s built-in test environment, or the standard `install-wp-tests.sh` script), so tests can exercise real WordPress functions, hooks, and a real (throwaway) test database rather than hand-mocking WordPress core behavior.
* **Local development environment**: `wp-env` (the official WordPress Docker-based tool) for day-to-day iteration. This does not carry a real PMPro license or WordPress.com-specific behavior, so it is for fast dev-loop iteration only.
* **Pre-release validation environment**: the WordPress.com Business Plan staging site, a full mirror of the live BITS site including its real PMPro configuration. This is where Phase 8's hardening pass happens; it is not part of the automated CI test run.

### 3. Unit Tests

* Unit tests never make real network calls. The Groups.io API client's HTTP transport is mocked (e.g., via WordPress's `pre_http_request` filter, or an injected fake HTTP client), and tests assert that the client builds correct requests and handles various mocked responses correctly — success, the confirmed error shape from PRD section 7/9, 429 with `Retry-After`, and timeout/5xx.
* Unit tests run on every pull request as part of CI, fast and free of external dependencies.
* Every first-order class has a unit test file named to reflect the class it tests (per `CLAUDE.md`'s Linting and Code Quality section), and every first-order public method has a corresponding test function.
* Specific unit-test responsibilities include: the delta calculation (given a mocked `list_subscriptions` and a mocked Groups.io membership state, compute the expected add/remove set, including the `(subgroup id, email)` pairing behavior from PRD section 3.3); the grace-period scheduling and cancellation logic; the magic link token generation, hashing, expiry, and single-use invalidation logic; the mass-action anomaly threshold detection logic; the CAN-SPAM global opt-out flagging logic.

### 4. Integration Tests

* Integration tests make real network calls to the dedicated test Groups.io group(s) that already exist for this project (confirmed available). They are never run against the production BITS Groups.io group.
* Per the explicit decision made in planning, integration tests run automatically on every pull request, using a test-group Groups.io API key stored as a GitHub Actions secret and scoped via environment protection rules (per the Security document, section 6).
* Integration test responsibilities include: the Groups.io API client's real add/remove/list operations against the test group (confirming the actual contract established in Phase 2); end-to-end join/change sync against a test PMPro-equivalent state; end-to-end removal (expiration, cancellation, GDPR erasure) against the test group; drift reconciliation correcting a manually-induced mismatch in the test group; the magic link request-to-login flow, run against the local/test WordPress environment (this part doesn't require Groups.io itself, only a real WordPress session).
* Because integration tests consume real Groups.io API rate-limit budget on every PR, test setup should reuse/clean up test-group state between runs (e.g., removing test members added during a run) rather than accumulating orphaned test data over time.

### 5. Coverage

* CI enforces an 80% code coverage threshold as a gate, per `CLAUDE.md`. Coverage is computed from the unit test suite; integration test coverage is not counted toward this threshold, since integration tests depend on external network availability and shouldn't be a hard blocker for the coverage gate itself.
* A pull request that drops coverage below the threshold fails CI and cannot merge.

### 6. Accessibility Testing

* Automated tests do not verify WCAG compliance; that is a manual screen reader verification step (per `CLAUDE.md`'s Accessibility by Design section), performed by the primary contributor for day-to-day changes and as a fuller screen reader matrix pass gating Phase 8 and any merge to `dev` that introduces or changes a UI surface.

### 7. What Is Explicitly Not Automated Yet

* Load/scale testing (e.g., simulating BITS's full membership base hitting reconciliation simultaneously) is not part of the automated suite for v1; Phase 5's exit criterion relies on a smaller, manually-verified test-group scenario instead. If BITS's membership scale later warrants it, a dedicated scale-testing pass can be added as future scope.
