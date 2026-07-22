# Copilot Instructions — BITS Groups.io Membership Sync

This is a WordPress plugin that automates BITS (Blind Information Technology
Solutions) Groups.io mailing list membership from Paid Memberships Pro (PMPro)
membership status. It is built by and for a blindness-focused nonprofit —
accessibility is a release gate here, not a nice-to-have.

The full documentation set lives in `docs/` and is the source of truth this
file summarizes. When in doubt, read the linked document rather than
guessing: `docs/PRD.md`, `docs/phase-plan.md`, `docs/security.md`,
`docs/testing-standard.md`, `docs/implementation-standard.md`, `docs/ci.md`,
`docs/ux-accessibility.md`, `docs/definition-of-done.md`,
`docs/acceptance-criteria-standard.md`.

Two path-scoped instruction files add more detail on top of this one:
`.github/instructions/frontend.instructions.md` (UI/accessibility) and
`.github/instructions/security-sensitive.instructions.md`
(credentials/Groups.io API/audit data).

## Process

* Order of operations for any unit of work: Plan → Design → Track →
  Implement → Test. A design is confirmed and a GitHub issue exists, with
  complete acceptance criteria, before implementation code is written. Don't
  suggest or write substantial new functionality that skips this — flag that
  it needs a tracked issue first instead.
* Every GitHub issue for Feature/Bug work needs formal acceptance criteria:
  Given/When/Then (features, including at least one accessibility-focused
  scenario when the feature touches a UI surface) or Steps to
  Reproduce/Expected/Actual/Given-When-Then (bugs).
* Branch naming, checked by CI (`branch-name-lint`): `feature/*` (new
  functionality), `bug/*` (bug fixes), `docs/*` (documentation-only),
  `infra/*` (tooling/CI, not itself shipped plugin behavior).
* `CHANGELOG.md` (Keep a Changelog format) is the source of truth for
  version history. Every `feature/*`/`bug/*` PR must add an entry under its
  `## [Unreleased]` section, under the matching subheading (`### Added`/
  `### Changed` for features, `### Fixed`/`### Security` for bug fixes).
  `docs/*` and `infra/*` branches are exempt — enforced by CI
  (`changelog-check`).
* No feature is built beyond what the current, confirmed v1 scope requires
  (see `docs/PRD.md` section 2). Don't scaffold or partially implement
  speculative future scope.
* A bug fix stays scoped to the bug — don't bundle unrelated refactoring or
  cleanup into the same change.

## Architecture and Style

* Namespace: `BITS\GroupsIOSync`, PSR-4 autoloaded from `includes/` (flat
  directory, no subnamespaces). Tests mirror this under
  `BITS\GroupsIOSync\Tests\Unit`, in `tests/Unit/`, one test file per class.
* Classes are static-method utility classes by default (see `Settings`,
  `AuditLog`, `GroupsIoApiClient`, `SubgroupIdCache`) — not instantiated,
  no constructor-injected dependencies. This matches how unit tests mock
  WordPress behavior (see Testing below), so don't introduce instance state
  or dependency injection without a concrete reason tied to testability.
* Every first-order class has exactly one primary responsibility, per
  `CLAUDE.md`'s Linting and Code Quality section. Closely-related helper or
  configuration types (e.g., a small exception hierarchy for one class) are
  fine as sibling classes in the same PR; unrelated concerns are not.
* PHP minimum version: 8.0. Use typed properties/parameters/returns
  throughout — this codebase already does, and PHPStan (level 5,
  `phpstan-wordpress` extension) enforces it.
* Coding standard: WordPress Coding Standards (WPCS) via PHP_CodeSniffer,
  run in CI. Every method has a docblock with a short description (not just
  `@param`/`@return`), and multi-parameter docblocks align their `@param`
  type/name columns — WPCS enforces exact spacing here, not just "close
  enough."
* A WPCS `ExceptionNotEscaped` finding on a `throw new SomeException( $var )`
  call is a known false positive in this codebase for internal exception
  messages that are never rendered as HTML output — suppress with
  `// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped --
  <reason>`, don't wrap exception messages in `esc_html()`/`esc_html__()`,
  which doesn't apply semantically to non-rendered strings.

## Testing

* Unit tests never make real network calls. HTTP is mocked via WordPress's
  `pre_http_request` filter (see `GroupsIoApiClientTest`,
  `SubgroupIdCacheTest` for the pattern), not an injected fake transport.
* Tests run against the real WordPress core PHPUnit test suite bootstrap
  (`WP_UnitTestCase`), not hand-mocked WordPress functions.
* Coverage is gated at 80% in CI, computed from the unit suite only.
* Integration tests (separate from unit tests) make real calls to a
  dedicated test Groups.io group — never the production BITS group, and
  never run outside CI/an explicit local opt-in.
* If a method's behavior depends on a value that can't be toggled at
  runtime in PHP (e.g., a defined constant like `WP_DEBUG`), extract the
  pure/testable part into its own method rather than trying to work around
  the constant in the test (see `GroupsIoApiClient::build_debug_log_line()`
  for the pattern).

## Security

* No credential (Groups.io API key, or anything else) is ever stored in the
  database, exposed through an admin UI field, or committed to the
  repository — including as a placeholder that looks real. Credentials are
  `wp-config.php` constants only (`GROUPS_IO_API_KEY`,
  `GROUPS_IO_PARENT_GROUP`, `GROUPS_IO_NOTIFICATION_EMAIL`).
* See `.github/instructions/security-sensitive.instructions.md` for more
  detail on code that touches credentials, the Groups.io API, or stored
  audit data.

## Documentation

* If a change diverges from what a design document already says, update
  that document to match what was actually built — don't let `docs/`
  silently drift out of date.
* This file and `.github/instructions/*.instructions.md` are themselves
  part of that documentation set — keep them in sync with `docs/` as
  conventions evolve.
