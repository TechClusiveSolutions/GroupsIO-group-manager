# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- New "GroupsIO Management" top-level admin menu (`includes/Admin/`), with three submenu pages: User Assignment (default landing page, placeholder pending #35), Feature Controls (the relocated Phase 1 Settings page - same underlying storage and rendering, only its menu location and title changed), and Subgroup Management (see below).
- Subgroup Management admin surface (`Admin\SubgroupManagementPage`), across three views chosen for a low-density, screen-reader-friendly layout: a List view showing the total subgroup count, the parent group's own full address, and every subgroup as a link whose text is its full address (not a slug); a Create view (Name/Title/Description fields); and a Details view (editable Name/Title/Description, live member list, Update and Delete). Every write (create, update, delete) is backed by a live-verified Groups.io API call with a follow-up read-back that re-fetches the subgroup list to confirm the change actually took effect before reporting success. Update sends only the fields that actually changed. Update and Delete re-validate the submitted subgroup id/slug against a fresh listing before acting, since both come from editable form fields. Delete reveals an inline confirmation on the Details view itself, no separate confirmation page. API errors are surfaced in plain language (never a raw error dump or machine-oriented error type). A Name change is a true rename via `updategroup`'s `name` parameter (confirmed by live trial to update the slug/URL/email/subject-tag consistently, unlike its separate cosmetic-only `title` parameter) - the page also warns that renaming does not update any PMPro level's mandatory-groups list referencing the old address. POST actions are processed on this page's `load-{hook}` action rather than inside its render callback, since WordPress always prints the admin header before a page's render callback runs - redirecting from inside render() fails with "headers already sent" on a real submission.
- `GroupsIoApiClient::update_subgroup()`: partial-updates a subgroup (`name`, `title`, and/or `desc`) via the live-verified `updategroup` contract.
- Playwright E2E test suite (`tests/e2e/`) covering every admin-UI feature implemented so far: the GroupsIO Management menu structure, Feature Controls (load/edit/save/persist), and Subgroup Management's full List/Create/Details lifecycle across all three views, including one dedicated end-to-end test exercising a real login through to a Groups.io update. A WordPress mu-plugin (`tests/e2e/mu-plugins/groupsio-api-mock.php`, loaded only for the fake dev/test API keys) mocks the Groups.io API responses so these tests are fast, deterministic, and require no live credentials, while still exercising the plugin's real request path. Runs as a new `e2e-tests` CI job on every PR.
- `docs/index.md`'s Brief Usage Overview updated to describe the new GroupsIO Management admin menu (Feature Controls, Subgroup Management, and the still-pending User Assignment), replacing its outdated description of a single settings screen.
- Paid Memberships Pro added as a dev/CI dependency (`strangerstudios/paid-memberships-pro` via Composer, since PMPro was permanently removed from the WordPress.org plugin directory on 2024-10-17 and is no longer installable via `wp plugin install`) so this plugin's PMPro-dependent code can be tested against a real PMPro install instead of only mocked hooks. Installed and activated on the wp-env dev site for manual/screen-reader testing, with a real membership level created for that purpose. The wp-env test site is intentionally excluded - dev-only going forward.

- `GroupsIoApiClient`: wraps the confirmed Groups.io API contract
  (`directadd`, `removemember`, `getgroup`, `getsubgroups`, `getmembers`)
  over `wp_remote_request`, with typed exceptions for rate limiting,
  transport failures, and the confirmed error-type response shape, and
  `WP_DEBUG` request/response logging with credentials never logged.
- `SubgroupIdCache`: caches the slug-to-numeric-`group_id` mapping
  Groups.io requires for member-level operations, with explicit
  invalidation and a full-refresh path for reconciliation.
- `GroupsIoApiClient::create_subgroup()` / `::remove_subgroup()`: wrap
  the live-verified `createsubgroup`/`deletegroup` contract (`deletegroup`
  is the only deletion endpoint for subgroups), following the client's
  existing conventions and exception dispatch.
- Permanent subgroup-lifecycle integration test
  (`tests/integration/SubgroupLifecycleIntegrationTest.php`), run
  automatically on every pull request against the real test Groups.io
  group via a new `integration-tests` CI job, gated on the
  `groupsio-test-group` GitHub Environment and restricted to PRs
  originating from within the org.

### Fixed

- `LevelMandatoryGroups::render_field()` fataled on every real PMPro Edit Level page load (`Argument #1 ($level_id) must be of type int, stdClass given`) - it was typed to expect a plain level id, but PMPro's `pmpro_membership_level_after_other_settings` action actually passes the full level object. Only surfaced once a real PMPro install existed to load that page against; previously masked by unit tests that called the method directly with a synthetic int. Now accepts the level object, matching the real hook contract.
- Subgroup Management: creating a subgroup with a Description now verifies via read-back that the description was actually applied, not just that the subgroup exists - a mismatch (e.g. Groups.io ignored or hasn't yet propagated it) is reported as a distinct "created, but description could not be confirmed" outcome rather than a plain creation failure, for the same reason a title-set failure isn't reported as `create_failed`.
- Subgroup Management: creating a subgroup with both a Title and a Description no longer silently skips setting the Title when the Description fails read-back confirmation - the Title is now always attempted regardless of the Description outcome, and only afterward is the description mismatch reported.
- Subgroup Management: the description-confirmation check is now re-evaluated against the later read-back taken after a title update, instead of staying stuck on the earlier, possibly-stale first read-back - a description that had actually propagated by the time the title was set no longer gets reported as a failure.
- Subgroup Management: notice text (created/updated/deleted/failure messages) is now translated via `__()` at render time instead of being stored untranslated in a class constant, matching the rest of the page's strings.
- Subgroup Management: a retried/duplicate delete that reaches the actual `deletegroup` call (not just the pre-check listing) and gets back `group_not_found` is now treated as an already-achieved success, per this project's idempotent-tolerance requirement for Groups.io-touching operations, instead of being reported as `delete_failed`.
- Subgroup Management: `friendly_error()` no longer surfaces the raw `HTTP <code>: <body>` dump Groups.io's `unexpected_status` error type carries as its detail - that's an internal diagnostic string, not a plain-language message fit for an admin notice.
- `docs/testing-standard.md` and `docs/ci.md` now document the Playwright E2E test job (scope, mocking approach, environment) that was already running in CI but wasn't described in either document; `tests/e2e/README.md` corrected a stale file path reference.
- Subgroup Management: the delete pre-check no longer treats a missing subgroup id alone as proof the delete already happened - it now also checks whether the submitted slug is in use by a *different*, current subgroup (e.g. an old subgroup was deleted and its slug got reused) and reports `not_found` in that case instead of falsely reporting success and invalidating a cache entry that points at a live subgroup.
- Subgroup Management: the List view's parent-group lookup now treats `unauthorized_error`/`inadequate_permissions` as a hard-stop signal (per `security-sensitive.instructions.md`) - it previously treated every failure the same and proceeded to make further Groups.io calls that would fail identically.
- `docs/index.md`'s usage overview no longer claims Feature Controls includes a working audit log - that recording/reading is deferred to a later phase, only the underlying table exists today.
- Subgroup Management: the Details view's Update-form Name field carried autofocus unconditionally, so when the delete confirmation was also showing, both it and the confirmation button had autofocus - per the HTML spec only the first one in document order actually receives focus, silently defeating the confirmation button's. Name-field autofocus is now suppressed while confirming delete.
- Subgroup Management: a Groups.io API/transport failure during the read-back checks used to validate a submitted subgroup id/slug (or to confirm a create/update/delete actually took effect) was indistinguishable from a genuine "not found" or "confirmed deleted" result - a failed check could be silently reported as success. Lookup failures now propagate and are reported as their own distinct failure, never collapsed into a false-positive result.
- Subgroup Management: deleting an already-absent subgroup (e.g. a retried or duplicate delete request) now succeeds idempotently instead of returning a "not found" error, per this project's idempotent-tolerance requirement for Groups.io-touching operations.
- Subgroup Management: if creating a subgroup succeeds but a follow-up Title-setting call fails, this is now reported as a distinct "created, but title failed" outcome rather than a plain creation failure - the prior wording invited a retry that would have collided with the subgroup that already exists.
- `GroupsIoApiClient::update_subgroup()`: $fields is now restricted to its documented keys (`name`, `title`, `desc`) before being merged into the request body, so an unexpected `group_id` key can no longer override the method's own $subgroup_id parameter and retarget the request.
- Subgroup Management: renaming a subgroup now invalidates the `SubgroupIdCache` entry for both the old and the new slug, not just the old one - a stale entry for the destination slug (left behind by a different, since-deleted subgroup that once used that name) could otherwise survive the rename.
- Subgroup Management: the List view no longer claims "0 subgroups provisioned" when the subgroup list actually failed to load - the count is now only shown after a successful load.
- Subgroup Management: the Name field's description now includes the existing warning that renaming does not update any membership level's mandatory-groups list - previously only present on the old single-page design's rename form, dropped during the redesign.

- `GroupsIoApiClient::direct_add()`: fixed two contract bugs found by
  live trial via the #32 integration test — emails must be
  newline-separated (comma-joined input was parsed by Groups.io as a
  single invalid address), and subgroup ids must be sent as the plural,
  comma-separated `subgroupids` field, not a repeated singular
  `subgroupid` field (which Groups.io silently ignored, so adds never
  actually reached the subgroup, only the parent). Present since Phase
  2's original implementation (#24), predating the live API
  verification that would have caught it.

## [0.1.0] - 2026-07-20

### Added

- Plugin scaffold: bootstrap, audit table (`bits_groupsio_audit`), and CI.
- Admin settings screen (Settings > BITS Groups.io Sync) for the database-backed
  operational settings: global mandatory groups, grace period, log retention
  policy, kill switch, mass-action anomaly threshold, magic link rate limits,
  and reconciliation dry-run mode, with clamped numeric bounds.
- Level-specific mandatory groups meta box on the PMPro Edit Membership Level
  screen.
- Per-field accessibility descriptions (`aria-describedby`), native label
  association, and autofocus on the first field of the settings screen.
- A Reset button on the settings screen, and a disabled "Run Dry-Run Now"
  stub ahead of the Phase 6 reconciliation engine.

### Fixed

- `Settings::sanitize_lines()` no longer corrupts array input by casting it
  to the literal string `"Array"`; array elements are now treated as
  individual lines.
- Unescaped `autofocus` attribute output on the settings screen (WPCS
  `OutputNotEscaped`).
