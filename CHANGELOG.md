# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- New "GroupsIO Management" top-level admin menu (`includes/Admin/`), with three submenu pages: User Assignment (default landing page, placeholder pending #35), Feature Controls (the relocated Phase 1 Settings page - same underlying storage and rendering, only its menu location and title changed), and Subgroup Management (see below).
- Subgroup Management admin page (`Admin\SubgroupManagementPage`): list all subgroups with live member counts and a per-row "view members" expansion; create, rename, and delete subgroups, each backed by a live-verified Groups.io API call with a follow-up read-back; delete requires a nonce-protected confirmation step; API errors are surfaced in plain language, never a raw error dump; the create form stays usable even if the list itself fails to load. Rename uses `updategroup`'s `name` parameter (confirmed by live trial to update the slug/URL/email/subject-tag consistently, unlike its separate cosmetic-only `title` parameter) - the page also warns that renaming does not update any PMPro level's mandatory-groups list referencing the old slug.
- `GroupsIoApiClient::update_subgroup()`: renames a subgroup via the live-verified `updategroup` `name` parameter contract.

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
  stub ahead of the Phase 5 reconciliation engine.

### Fixed

- `Settings::sanitize_lines()` no longer corrupts array input by casting it
  to the literal string `"Array"`; array elements are now treated as
  individual lines.
- Unescaped `autofocus` attribute output on the settings screen (WPCS
  `OutputNotEscaped`).
