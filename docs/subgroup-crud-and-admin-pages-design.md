# Subgroup CRUD and GroupsIO Management Admin Pages Design

## BITS Groups.io Membership Sync — Phase 2 (amended) and Phase 3

### 1. Purpose

This document specifies the design of the 2026-07-22 scope expansion: adding
subgroup create/remove to `GroupsIoApiClient` (amending Phase 2), a
permanent CI-automated integration test for that client, and the new "GroupsIO
Management" admin area (Phase 3) — three distinct admin pages (User
Assignment, Feature Controls, Subgroup Management). It is the
design-confirmation step required before tracking issues are opened and
code is written, per `CLAUDE.md`'s Order of Operations. See
`app/docs/phase-plan.md` Phase 2's added bullets and the new Phase 3 for
the approved scope this document implements against.

### 2. Scope

In scope for this pass:

* `GroupsIoApiClient::create_subgroup()` and `::remove_subgroup()`.
* Live verification of the create/remove-subgroup HTTP contract against the
  test group — **not yet done**, unlike the rest of the client's contract
  (see section 3 below).
* Investigation of whether Groups.io exposes a real per-member
  moderation/suspend state on a subgroup, distinct from full removal —
  **not yet done**; this document specifies how the User Assignment page
  behaves in either outcome (section 6).
* A permanent CI-automated integration test exercising create → add → remove →
  delete against the test group.
* The "GroupsIO Management" admin menu category and its three pages: User
  Assignment, Feature Controls, Subgroup Management.
* The sticky manual-override flag data model that Phase 6's drift
  reconciliation will later have to respect (the flag itself is built now;
  reconciliation honoring it is Phase 6's responsibility, not this pass's).

Explicitly not in scope for this pass (later phases):

* Actually building Phase 6's drift reconciliation logic that reads the
  sticky-override flag — this pass only creates the flag and writes it.
* Kill-switch *enforcement* (Phase 7) — Feature Controls only relocates the
  existing toggle UI.
* **Deferred 2026-07-27**: implementing "suspend" as a User Assignment page
  action. The investigation in section 4 below is resolved (native
  `banmember` is broken server-side; fallback approach is
  `remove_member()` + the sticky-override flag), but suspend is not a
  critical feature and its *implementation* is pushed to Phase 6, where it
  is folded in alongside the drift-reconciliation logic that already has to
  understand the sticky-override flag. This pass's User Assignment page
  ships with Add/Remove only (section 10).
* Any Action Scheduler job wiring for subgroup CRUD — these are direct,
  synchronous admin actions initiated from a page click, not background
  jobs, matching how PRD-scoped admin actions work elsewhere in this
  plugin.

### 3. Client Additions: `create_subgroup()` / `remove_subgroup()` — Resolved (see also section 9 for the later `update_subgroup()` / rename resolution)

Per this project's established practice (`CLAUDE.md`, PRD section 9), the
HTTP contract for these two operations must be confirmed by live trial
against the test group before implementation, not assumed from public
docs. **This has not yet happened.** Before implementation begins:

* Verify the endpoint path, HTTP method, required parameters (expected:
  something like `POST createsubgroup` with `group_name`/parent id and a
  new subgroup name/slug; `POST removesubgroup` — or possibly `deletegroup`
  — with a numeric subgroup `group_id`), and the exact response/error shape
  on success and on failure (e.g., duplicate subgroup name, subgroup not
  found, permission denied).
* Confirm whether these dispatch through the same `{"object":"error",
  "type":"..."}` error shape already handled by the existing exception
  hierarchy, or introduce a new `type` value that needs to be added to the
  dispatch tests (no new exception class expected — per the existing
  design's flat-hierarchy rationale, an unrecognized `type` string already
  surfaces correctly via `get_error_type()`).

Once verified, the two methods follow the existing client's conventions
exactly (`includes/GroupsIoApiClient.php`, `public static`, same
credential-handling and `WP_DEBUG` redacted-logging behavior as every other
method):

* `create_subgroup( string $parent_group_name, string $subgroup_name ): array`
  — returns the decoded response (expected to include the new subgroup's
  numeric `id`) on success.
* `remove_subgroup( int $subgroup_id ): array` — deletes a subgroup by its
  numeric ID. Single-ID per call, no batching, consistent with
  `remove_member()`'s established one-call-per-target pattern unless live
  trial shows otherwise.

### 4. Suspend-State Investigation — Resolved; Implementation Deferred to Phase 6

Groups.io's publicly documented API does not confirm a per-member
moderation/ban state scoped to a single subgroup (as opposed to removing
membership entirely). Live trial against the test group (2026-07-25)
resolved this:

* Groups.io DOES document a native per-member ban state (`status` =
  `sub_status_banned`, via `banmember`/`unbanmember`), and `unbanmember`
  provably works (400 `"user not banned"` on a non-banned member).
* **But `banmember` itself is broken server-side**: even with the exact
  documented parameters, it returns an nginx-level HTML 404 — the request
  never reaches the application router, unlike genuinely nonexistent
  endpoints which get the app's own plain-text 404. This is a Groups.io
  server-side routing problem, not a client error. `updatemember` also
  silently ignores attempts to set `status` to `sub_status_banned`
  (200, no-op) — banning is only reachable via the broken `banmember`.

**Decided fallback** (per the "if it doesn't exist" branch originally
planned for below): "suspend" is implemented as `remove_member()` under
the hood, with the plugin's own audit/override-flag layer (section 6)
recording the action as `suspended` rather than a normal `removed`, so an
admin can still distinguish and later "un-suspend" (which becomes a
re-`direct_add()` restoring the exact prior subgroup set, read back from
the audit record). This fallback still requires the sticky-override flag
from section 6, since a bare `remove_member()` call by itself is
indistinguishable from any other removal and would otherwise be
reconciled away as drift.

**Deferred 2026-07-27**: since suspend is not a critical feature, its
implementation is pushed to Phase 6 (see `phase-plan.md`), where it is
folded in alongside the drift-reconciliation logic that already has to
understand the sticky-override flag, rather than built now. Before that
implementation begins, re-check whether `banmember` has been fixed
server-side; if so, prefer the native primitive (the "if it exists" branch
below) over the fallback.

The original two-outcome plan, preserved for when Phase 6 implements this:

* **If `banmember` is fixed/exists**: `GroupsIoApiClient` gets a
  `set_member_status()` (or similarly named) method wrapping it, and
  "suspend" in the User Assignment page maps directly to that real
  Groups.io state — an admin can suspend and later un-suspend a member
  without a full remove/re-add round trip.
* **If it's still broken**: use the decided fallback above.

### 5. Automatic CI Integration Test

**Corrected 2026-07-27**: this section previously said the integration
test would run manually, citing `testing-standard.md` section 4 as
support for that — that was a misreading. Section 4 (and `ci.md` section
3.2) actually specify the opposite: integration tests run automatically
on every pull request, using a test-group Groups.io API key stored as a
GitHub Actions environment secret, restricted to PRs originating from
within the `TechClusiveSolutions` org (not fork PRs), and a failure
blocks merge. This section now matches that.

A test living alongside the existing unit tests
(`tests/integration/SubgroupLifecycleIntegrationTest.php`), run via a
dedicated `integration-tests` job in `ci.yml` (separate from the
unit-tests job, since it needs the real credential and hits real network)
gated on the `groupsio-test-group` GitHub Environment, that:

1. Creates two test subgroups under the configured parent test group.
2. Adds three test email addresses to both subgroups (`direct_add()`,
   batched per subgroup as the existing client already supports).
3. Removes all three emails from both subgroups (`remove_member()`, looped
   per the existing one-call-per-target pattern).
4. Deletes both subgroups (`remove_subgroup()`).

Each step's success is verified both by the client's return value and, per
this project's "verify live, don't just trust the response" practice
established in the original Phase 2 design, a follow-up `get_members()` /
`get_subgroups()` read-back confirming the actual state before proceeding
to the next step. Test subgroup names are timestamped/randomized to avoid
collisions with any leftover state from a prior partial run.

### 6. Sticky Manual-Override Flag — Data Model

* Storage: a new column or serialized field on the existing
  `bits_groupsio_audit` table (exact shape decided at implementation time,
  consistent with that table's existing structure) recording, per
  `(member, subgroup)` pair: that the current state was set by manual admin
  action, which admin, when, and the action type (`added` / `removed` /
  `suspended`).
* Drift reconciliation (Phase 6, not built in this pass) is expected to
  check this flag and skip any `(member, subgroup)` pair carrying it,
  rather than correcting it back to the PMPro-derived expected state.
* The flag is cleared automatically when the member's PMPro level changes
  (a fresh join/upgrade/downgrade supersedes a stale manual override), or
  explicitly by an admin action on the User Assignment page ("clear
  override" / "resume automatic management" for that member).

### 7. Admin Pages: Menu Structure

* A single new top-level WordPress admin menu item, "GroupsIO Management,"
  registered via `add_menu_page()`, with three submenu pages registered via
  `add_submenu_page()`:
  1. User Assignment (default landing page for the top-level menu click).
  2. Feature Controls.
  3. Subgroup Management.
* Each page is its own class under `includes/Admin/` (new subdirectory,
  since this is the first admin-page-per-class split in the codebase;
  existing `Settings.php` stays where it is per section 8 below), following
  the single-responsibility-per-class rule in `CLAUDE.md`.
* All three pages follow the accessible-admin-UI patterns already
  established in `Settings.php` and documented in
  `.github/instructions/frontend.instructions.md`: native `<label for>`,
  `aria-describedby` field descriptions, autofocus on the first field only,
  correct context-specific escaping, and explained-disabled-controls.

### 8. Page: Feature Controls

* Renames and relocates the existing Phase 1 `Settings` admin page
  (global mandatory groups, grace period, log retention policy, kill
  switch) under the new "GroupsIO Management" menu, replacing its current
  standalone menu placement. The underlying `Settings` class and its
  option storage are not rebuilt — only the menu registration and page
  title/labels change to reflect the new location.
* No new functionality in this pass beyond the relocation itself.

### 9. Page: Subgroup Management

* Lists all subgroups under the configured parent group (via
  `SubgroupIdCache` / `GroupsIoApiClient::get_subgroups()`), each with its
  current member count and a "view members" expansion
  (`GroupsIoApiClient::get_members()`) showing the actual member list for
  that subgroup.
* "Create subgroup" form: name/slug input, calls
  `create_subgroup()` (section 3), then invalidates/refreshes
  `SubgroupIdCache` so the new subgroup is immediately selectable elsewhere
  in the plugin (e.g. the level-mandatory-groups meta box from Phase 1).
* "Delete subgroup" action per row, with a confirmation step (WordPress's
  standard `wp_nonce`-protected confirmation pattern), calling
  `remove_subgroup()` and invalidating the cache entry for that subgroup.
* "Update" (rename): **resolved 2026-08-04.** `updategroup` supports a
  `name` parameter (form `ParentGroupName+SubGroupName`) that performs a
  full rename — confirmed by live trial to update the subgroup's `name`,
  `group_url`, `email_address`, and `subject_tag` consistently. This is
  distinct from `title`, a separate cosmetic display-only field that does
  **not** change the slug/URL/email/subject-tag (also confirmed by live
  trial). The Subgroup Management page's rename action uses `name`, not
  `title`.

### 10. Page: User Assignment

* Member lookup (by email or WordPress user search) showing that member's
  current subgroup memberships (`get_members()` cross-referenced against
  the member's known email) and their PMPro-derived expected set, so an
  admin can see at a glance where the two disagree.
* Per-subgroup row: Add / Remove action buttons. (Suspend is deferred to
  Phase 6 per section 4 — not built in this pass.)
  * Add → `direct_add()` for that one subgroup, sets the sticky-override
    flag (section 6) as `added`.
  * Remove → `remove_member()`, flag set as `removed`.
* "Clear override" per row, removing the sticky flag and letting the next
  reconciliation run (Phase 6) resync that pair to the PMPro-derived state
  normally.
* Every action writes an audit log entry (existing `AuditLog` class),
  consistent with how automated sync actions are already logged.

### 11. Testing Approach

* `GroupsIoApiClient::create_subgroup()`/`remove_subgroup()`: unit-tested
  against mocked HTTP responses (success, duplicate/conflict error,
  not-found error), same `pre_http_request` mocking convention as the rest
  of the client.
* Sticky-override flag read/write logic: `WP_UnitTestCase`-based unit
  tests against the real WordPress test database, per existing convention.
* The three admin page classes: unit-tested for their data-handling logic
  (form processing, nonce verification, capability checks) using
  `WP_UnitTestCase`; a manual screen-reader pass by the primary contributor
  is required before any of the three pages is considered done, per
  `CLAUDE.md`'s Accessibility by Design section — this is not satisfied by
  automated tests alone.
* The permanent integration test (section 5) runs automatically in CI on
  every pull request, gated on the `groupsio-test-group` environment, per
  `testing-standard.md` section 4 and `ci.md` section 3.2.

### 12. Exit Criteria (per `phase-plan.md`)

Reproduced from `phase-plan.md` for traceability — see that document as
the authoritative source if the two ever diverge:

* **Phase 2 (amended)**: `create_subgroup()`/`remove_subgroup()` are
  unit-tested and live-verified; the suspend-state investigation is
  resolved one way or the other; the permanent integration test passes
  automatically in CI against the test group.
* **Phase 3**: an admin can reach all three GroupsIO Management pages; can
  add/remove a test member's subgroup assignment from User Assignment with
  the sticky-override flag verifiably respected by a subsequent
  reconciliation run (once Phase 6 exists to test that end-to-end — until
  then, verify the flag is at least correctly written and readable); can
  save/retrieve operational settings from Feature Controls; and can
  create, list, update, and delete a subgroup and view its membership from
  Subgroup Management — all verified against the test group. (Suspend is
  deferred to Phase 6 — see section 4.)
