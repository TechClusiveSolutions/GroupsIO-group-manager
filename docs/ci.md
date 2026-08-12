# CI Document

## BITS Groups.io Membership Sync

### 1. Purpose

This document specifies what CI runs, on what triggers, and what gates what, per `CLAUDE.md`'s CI Policy.

### 2. Trigger

* Both `dev` and `main` have branch protection enabled, with "include administrators" (admin-enforced) set to true, and no direct pushes permitted to either branch under any circumstance — all changes flow through a pull request.
* Branch protection on both branches does not require a formal GitHub approving review to merge a PR; merge authorization instead comes from the primary contributor's explicit go-ahead in conversation with the working AI assistant, per the project's working agreement. Separately from that authorization step, when active, every PR must also have GitHub Copilot requested as a reviewer at open time and again after every subsequent push made while the PR is already open (not commits made while building out a feature branch before a PR exists for it), and is not merge-eligible until Copilot's review has completed and every comment it raised has been addressed — this is a distinct gate from, and in addition to, the primary contributor's go-ahead. A quota-exhausted response ('unable to review... reached their quota limit') does not block merging - it is not the same as an unaddressed comment. **Discontinued again as of 2026-08-06**: the primary contributor has directed that Copilot not be requested as a reviewer - on new PRs or on subsequent pushes to already-open PRs - until they say otherwise. This is the second such discontinuation (the first ran 2026-07-27 through 2026-08-05, when it was explicitly resumed). Do not request Copilot as a reviewer in the meantime; resume only per explicit go-ahead.
* CI runs on every pull request opened or updated against `dev`, and on every pull request from `dev` to `main`.

### 3. Workflow Files

CI is split across three GitHub Actions workflow files:

* **`docs.yml`**: contains the documentation linting job (section 3.1) plus a no-op placeholder job for code, which always passes and does no actual work. The no-op job exists so that a required-status-check configuration referencing this workflow doesn't break for documentation-only PRs that don't touch code — it's a placeholder seam, not a real check, and is not a substitute for `ci.yml`'s real code jobs.
* **`ci.yml`**: the main workflow. It runs the documentation linting test (the same check defined for `docs.yml`) and the full set of code functional tests (section 3.2), so that every pull request — whether docs-only, code-only, or mixed — gets both documentation and code validated in one place.
* **`integration.yml`**: the live integration suite (section 3.3), a separate workflow that only ever runs on manual dispatch — it is not part of the automatic PR pipeline `ci.yml` runs.

#### 3.1 Documentation Linting

* A markdown linter (e.g., `markdownlint`) runs against all user-level documentation: everything under `docs/` in the outer working directory is not applicable here since it's outside version control (per `CLAUDE.md`'s Repository Layout), so this specifically covers user-facing documentation that does live in the `app` repository — `README.md`, the contribution policy, the code of conduct, and any other markdown intended for repo visitors. Failure blocks merge.

#### 3.2 Code Functional Tests

* **Lint**: PHP_CodeSniffer with the WPCS ruleset (per the Implementation Standard document, section 2). Failure blocks merge.
* **Static analysis**: PHPStan at the project's configured level. Failure blocks merge.
* **Unit tests**: the full PHPUnit unit suite (mocked HTTP, no network calls), against the WordPress core test suite bootstrap, on the supported PHP version matrix (PHP 8.0 as the floor; additional versions added to the matrix as adopted). Failure blocks merge.
* **Coverage gate**: computed from the unit test run; must be at or above 80% (per the Testing Standard document, section 5). Failure blocks merge.
* **E2E tests (Playwright)**: a browser-level end-to-end suite (`tests/e2e/`) covering the admin UI - the GroupsIO Management menu structure, Feature Controls, and Subgroup Management's full List/Create/Details lifecycle, including one test that exercises a real login through to a Groups.io update. Runs against a `wp-env` instance started in the job (`npx wp-env start`) with a safety-guarded WordPress mu-plugin (`tests/e2e/mu-plugins/groupsio-api-mock.php`) mocking the Groups.io API - the mock only activates for this project's established fake dev/test API keys, so it can never intercept a real credential's requests. No live Groups.io credentials are required or used. Failure blocks merge.

#### 3.3 Integration Tests (Manual Only)

* **Changed 2026-08-11**: the integration suite (`tests/integration/`), which makes real calls to the dedicated test Groups.io group(s) using the test-group credential stored as a GitHub Actions secret and scoped via environment protection rules (per the Security document, section 6), no longer runs automatically on pull requests. It lives in its own workflow, `integration.yml`, triggered only via manual `workflow_dispatch` (the Actions tab's "Run workflow" button, or `gh workflow run integration.yml`).
* Rationale: running real Groups.io API calls on every push consumed live rate-limit budget and made every PR's CI run dependent on a third-party service's availability, for a suite whose main value is confirming the actual Groups.io API contract - not something that changes on every commit. The mocked unit suite (section 3.2) already runs on every PR and is the CI-enforced gate for day-to-day changes.
* Not a merge gate: since it no longer runs automatically, a passing/failing integration run is not one of the checks required before merging a PR. A contributor touching `GroupsIoApiClient` or any Groups.io-calling code path is expected to manually trigger `integration.yml` against their branch before asking for merge approval, per the Testing Standard document, section 4.
* Still restricted by the same environment protection rules as before (test-group secret access), so a manual run still can't be dispatched against a fork-originated branch without the appropriate authorization.

### 4. Version Bumping and Releases

`CHANGELOG.md` (at the `app/` repository root, "Keep a Changelog" format) is the single source of truth for version history. The release process reads it rather than deriving a version from issue types, branch history, or commit messages.

* **Ongoing requirement**: every pull request merged into `dev` from a `feature/*` or `bug/*` branch must include an update to `CHANGELOG.md`'s `## [Unreleased]` section (under the appropriate "Keep a Changelog" subheading — `### Added`, `### Changed`, `### Fixed`, `### Security`, etc.). Enforced by a required CI check (`changelog-check` in `ci.yml`) that fails the PR if `CHANGELOG.md` isn't part of its diff.
* **Exemption**: pull requests from `infra/*` or `docs/*` branches are exempt from the `changelog-check` gate. Infrastructure/tooling work and documentation-only changes are not user-facing plugin behavior and are not tracked in version history.
* **Branch naming convention** (per `CLAUDE.md`'s branching strategy and `CONTRIBUTING.md`): `feature/*` for feature work, `bug/*` for bug fixes, `docs/*` for documentation-only changes, `infra/*` for tooling/CI/infrastructure work not itself part of the plugin's shipped behavior. A CI check (`branch-name-lint` in `ci.yml`) validates that a PR's head branch matches one of these four prefixes.
* **Cutting a release**: preparing a `dev`-to-`main` release pull request includes, as part of that PR (committed to `dev` first, like any other change), renaming `CHANGELOG.md`'s `## [Unreleased]` heading to `## [X.Y.Z] - YYYY-MM-DD` and adding a fresh, empty `## [Unreleased]` heading above it. The version number is a deliberate, human-confirmed decision made at this point — not computed automatically — consistent with this project's merge-authorization model (the primary contributor's explicit go-ahead in conversation, per section 2 above).
* **Release automation**: `release.yml` triggers on the `pull_request` event, `closed` type, filtered to pull requests where `base` is `main` and `merged` is `true`. It reads `CHANGELOG.md` at the merge commit, extracts the topmost dated `## [X.Y.Z] - YYYY-MM-DD` section (skipping the now-empty `## [Unreleased]` above it), sanity-checks that version is newer than the latest existing `vX.Y.Z` git tag, then creates and pushes an annotated tag and runs `gh release create <tag> --notes-file` using that section's own content as the release notes body — no auto-generated notes, no bump arithmetic.
* If the topmost `CHANGELOG.md` section's version is not newer than the latest tag (e.g., a `dev`-to-`main` merge that didn't include a release-prep step), the workflow fails loudly rather than silently skipping, since that indicates the release-prep step was missed.
* Major version bumps are not automatic — they only happen via an explicit release-branch cut at a defined phase boundary, per `CLAUDE.md`.

### 5. Syncing the Release Tag Back to `dev`

After tagging and publishing a release, `main` has a commit (the release merge commit) that `dev` does not, since `dev`'s own tip is one of that commit's two parents, not the commit itself. Left alone, this means the release tag is never an ancestor of `dev`'s history — `git describe --tags` run from `dev` would never find it.

* A second job in `release.yml`, gated on the tag-and-release job succeeding, handles this automatically, with no manual or approval step: it creates a short-lived branch from the just-tagged `main` commit (named `infra/sync-main-vX.Y.Z`, so it passes `branch-name-lint` and is exempt from `changelog-check` — it's pure bookkeeping, not new work), opens a pull request with `dev` as the base, and enables GitHub's native auto-merge (a real merge, not a squash, so the tagged commit becomes a true ancestor of `dev`) with branch deletion on completion.
* This still flows through a real pull request against a protected branch (satisfying `CLAUDE.md`'s "no direct pushes... under any circumstance"), and still waits for `dev`'s required status checks to pass before merging (via GitHub's auto-merge, not a busy-wait in the workflow) — it only skips the usual explicit human go-ahead in conversation, since the primary contributor has given blanket approval for this specific, structurally risk-free, content-identical sync-back operation ahead of time.

### 6. What CI Does Not Cover

* Screen reader / accessibility verification is manual (per the Testing Standard document, section 6) and is not a CI job.
* Deployment to the WordPress.com staging site or the live BITS site is not automated by CI in v1 — it remains a manual step, consistent with Phase 9 and Phase 10's exit criteria.
