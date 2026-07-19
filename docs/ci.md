# CI Document

## BITS Groups.io Membership Sync

### 1. Purpose

This document specifies what CI runs, on what triggers, and what gates what, per `CLAUDE.md`'s CI Policy.

### 2. Trigger

* Both `dev` and `main` have branch protection enabled, with "include administrators" (admin-enforced) set to true, and no direct pushes permitted to either branch under any circumstance — all changes flow through a pull request.
* Branch protection on both branches does not require a formal GitHub approving review to merge a PR; merge authorization instead comes from the primary contributor's explicit go-ahead in conversation with the working AI assistant, per the project's working agreement.
* CI runs on every pull request opened or updated against `dev`, and on every pull request from `dev` to `main`.

### 3. Workflow Files

CI is split across two GitHub Actions workflow files:

* **`docs.yml`**: contains the documentation linting job (section 3.1) plus a no-op placeholder job for code, which always passes and does no actual work. The no-op job exists so that a required-status-check configuration referencing this workflow doesn't break for documentation-only PRs that don't touch code — it's a placeholder seam, not a real check, and is not a substitute for `ci.yml`'s real code jobs.
* **`ci.yml`**: the main workflow. It runs the documentation linting test (the same check defined for `docs.yml`) and the full set of code functional tests (section 3.2), so that every pull request — whether docs-only, code-only, or mixed — gets both documentation and code validated in one place.

#### 3.1 Documentation Linting

* A markdown linter (e.g., `markdownlint`) runs against all user-level documentation: everything under `docs/` in the outer working directory is not applicable here since it's outside version control (per `CLAUDE.md`'s Repository Layout), so this specifically covers user-facing documentation that does live in the `app` repository — `README.md`, the contribution policy, the code of conduct, and any other markdown intended for repo visitors. Failure blocks merge.

#### 3.2 Code Functional Tests

* **Lint**: PHP_CodeSniffer with the WPCS ruleset (per the Implementation Standard document, section 2). Failure blocks merge.
* **Static analysis**: PHPStan at the project's configured level. Failure blocks merge.
* **Unit tests**: the full PHPUnit unit suite (mocked HTTP, no network calls), against the WordPress core test suite bootstrap, on the supported PHP version matrix (PHP 8.0 as the floor; additional versions added to the matrix as adopted). Failure blocks merge.
* **Coverage gate**: computed from the unit test run; must be at or above 80% (per the Testing Standard document, section 5). Failure blocks merge.
* **Integration tests**: the full integration suite, making real calls to the dedicated test Groups.io group(s), using the test-group credential stored as a GitHub Actions secret and scoped via environment protection rules (per the Security document, section 6). Failure blocks merge. This job is restricted by the environment protection rules to run only for PRs from within the `TechClusiveSolutions` org — not from external fork pull requests — since the test-group secret cannot be safely exposed to fork-originated workflow runs.

### 4. Version Bumping and Releases

* On a merge from `dev` to `main`, CI computes the version bump level from the GitHub issue type(s) closed by the merged work (feature-type issues bump minor, bug-type issues bump patch), per `CLAUDE.md`'s branching strategy.
* Every tag produced by this process triggers a GitHub Release with generated release notes.
* Major version bumps are not automatic — they only happen via an explicit release-branch cut at a defined phase boundary, per `CLAUDE.md`.

### 5. What CI Does Not Cover

* Screen reader / accessibility verification is manual (per the Testing Standard document, section 6) and is not a CI job.
* Deployment to the WordPress.com staging site or the live BITS site is not automated by CI in v1 — it remains a manual step, consistent with Phase 8 and Phase 9's exit criteria.
