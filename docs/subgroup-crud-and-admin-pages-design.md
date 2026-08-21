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

**Amended 2026-08-06**: the User Assignment page (section 12) is
substantially expanded from its original single-page Add/Remove/Clear-override
concept into a List/Details/Add-Groups view set with pagination, search, bulk
actions, and asynchronous queued execution — the original reconciliation
concept (PMPro-expected-set comparison, sticky-override flag) still applies
and is not replaced, only enhanced. This amendment also introduces a local
membership index table and sync job (section 6, expanded), real audit log
recording (section 7, new), and the first implementation of issue #50's
queued-execution-with-retry design (section 8, new) — none of which existed
in any form before this amendment.

### 2. Scope

In scope for this pass:

* `GroupsIoApiClient::create_subgroup()` and `::remove_subgroup()`.
* Live verification of the create/remove-subgroup HTTP contract against the
  test group — **resolved** (see section 3 below), along with the later
  update/rename contract (section 11).
* Investigation of whether Groups.io exposes a real per-member
  moderation/suspend state on a subgroup, distinct from full removal —
  **resolved** (see section 4): native `banmember` is broken server-side;
  the fallback is `remove_member()` plus the sticky-override flag. This
  document specifies how the User Assignment page behaves as a result
  (section 6).
* A permanent CI-automated integration test exercising create → add → remove →
  delete against the test group.
* The "GroupsIO Management" admin menu category and its three pages: User
  Assignment, Feature Controls, Subgroup Management.
* The sticky manual-override flag data model that Phase 6's drift
  reconciliation will later have to respect (the flag itself is built now;
  reconciliation honoring it is Phase 6's responsibility, not this pass's).
* **Added 2026-08-06**: a local membership index table mirroring actual
  Groups.io membership and the PMPro-expected-set comparison per
  `(member, subgroup)` pair (section 6); real `AuditLog` recording (section
  7); a queued, retried execution engine for User Assignment's add/remove
  actions with distinct per-outcome admin notifications, implementing issue
  #50 (section 8); and User Assignment's expanded List/Details/Add-Groups
  view set with pagination, search, and bulk actions (section 12).

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
  ships with Add/Remove only (section 12).
* Any Action Scheduler job wiring for subgroup CRUD — Subgroup Management's
  create/update/delete actions remain direct, synchronous admin actions
  initiated from a page click, not background jobs. (User Assignment's
  add/remove actions are the exception — see section 8 — since queuing was
  explicitly requested for that page, not for Subgroup Management.)
* A dedicated audit log *viewer* UI — this pass builds `AuditLog::record()`
  (section 7) so entries actually get written, but reading/displaying them
  is the "per-member audit view" already scoped to Phase 7 (Admin
  Operability) in `phase-plan.md`.
* **Added 2026-08-09**: a "Deleted Memberships" page — surfacing members
  whose `(member, subgroup)` rows carry `override_type = 'removed'`
  (excluded from the Details view's "currently subscribed" list per
  section 12, since #61). Scoped as a future-scope backlog item under
  Phase 7 (Admin Operability) in `phase-plan.md`, alongside the
  per-member audit view — not part of #61 or any currently-approved
  Phase 3 work.

### 3. Client Additions: `create_subgroup()` / `remove_subgroup()` — Resolved (see also section 11 for the later `update_subgroup()` / rename resolution)

Confirmed by live trial against the test group, per this project's
established practice (`CLAUDE.md`, PRD section 9):

* `createsubgroup` — `POST`, parameters `group_name` (parent group slug),
  `sub_group_name` (new subgroup's name segment), `desc` (optional
  description), `accept_policies` (documented as required; live calls
  succeeded without it, but the client always sends it anyway to match the
  documented contract). Returns the full new group object on success,
  including its numeric `id`.
* Subgroup deletion has no separate endpoint — `removesubgroup` and
  `deletesubgroup` both 404. `deletegroup` (`POST`, parameters `group_id`
  and the literal string `understand` = `"I understand"`) deletes a group
  *or* subgroup and is the only deletion endpoint. Returns HTTP 200 with an
  empty body on success; works even while the subgroup still has members.
* Both dispatch through the same `{"object":"error","type":"...","extra":"..."}`
  error shape already handled by the existing exception hierarchy — no new
  `type` value or exception class was needed; an unrecognized `type` string
  already surfaces correctly via `get_error_type()`.

The two methods follow the existing client's conventions exactly
(`includes/GroupsIoApiClient.php`, `public static`, same credential-handling
and `WP_DEBUG` redacted-logging behavior as every other method):

* `create_subgroup( string $parent_group_name, string $subgroup_name, string $description = '' ): array`
  — returns the decoded response, including the new subgroup's numeric `id`,
  on success.
* `remove_subgroup( int $subgroup_id ): array` — deletes a subgroup by its
  numeric ID via `deletegroup`. Single-ID per call, no batching, consistent
  with `remove_member()`'s established one-call-per-target pattern.

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

### 6. Local Membership Index & Sync Job — Includes the Sticky Manual-Override Flag

**Expanded 2026-08-06** from the original "Sticky Manual-Override Flag —
Data Model" section. Groups.io's API is subgroup-scoped (`get_subgroups()`,
`get_members()` for one subgroup at a time) — it has no member-centric
endpoint that can serve a single paginated, searchable "every member across
the parent group and all subgroups, with per-member group counts" view, which
section 12's redesigned User Assignment pages need. A local index table is
the practical way to serve that without live-aggregating across every
subgroup on every page load or search.

* **New table**, e.g. `bits_groupsio_member_index`: one row per
  `(member, subgroup)` pair, columns approximately `id`, `user_id`
  (nullable — not every Groups.io member is necessarily a matched WP user),
  `email`, `display_name`, `subgroup_id`, `subgroup_slug`, `subgroup_title`,
  `member_info_id` (nullable — Groups.io's own per-membership-record
  numeric ID for this `(email, subgroup)` pairing, taken verbatim from
  `get_members()`'s response; added 2026-08-07 once section 8's queued
  remove job turned out to need it — `GroupsIoApiClient::remove_member()`
  is keyed on `member_info_id`, not email or subgroup, and it isn't
  otherwise derivable without an extra live lookup at job-execution time),
  `pmpro_expected` (bool — whether this pairing is expected per the
  member's current PMPro level, computed from `LevelMandatoryGroups` +
  `Settings::global_mandatory_groups`), `override_type` (nullable —
  `added` / `removed` / `suspended`, the sticky-override flag itself, moved
  here from the original plan to store it on `bits_groupsio_audit` — this
  table is what the UI actually queries, so storing the flag alongside the
  membership row it applies to avoids a join), `override_by` (admin user
  id), `override_at` (datetime), `synced_at` (datetime, last confirmed
  against live Groups.io), `is_owner` (bool, added 2026-08-12 — whether
  this `(email, subgroup)` row's `mod_status` from `get_members()` is
  `sub_modstatus_owner`, per the confirmed live API contract; see the
  "Owner-removal safeguard" bullet below).
* **Sync job**: calls `get_subgroups()` then `get_members()` per subgroup,
  upserting rows to reflect actual current membership, and recomputing
  `pmpro_expected` per row. This is the first place the PMPro-expected-set
  computation (global + level-mandatory groups for a member's current
  level) actually gets built in this codebase — previously only the
  settings *storage* for those lists existed, not the comparison logic
  itself. Exact scheduling cadence (e.g. hourly via Action Scheduler
  recurring action) decided at implementation time.
* The parent group itself is represented as one of the "subgroup" rows for
  indexing purposes (every member is, in effect, always a member of the
  top-level parent group) — this is what gives the List page's "including
  parent" group count its `+1`.
* Drift reconciliation (Phase 6, not built in this pass) is expected to
  check `override_type` and skip any `(member, subgroup)` pair carrying it,
  rather than correcting it back to the PMPro-expected state.
* The flag is cleared automatically when the member's PMPro level changes
  (a fresh join/upgrade/downgrade supersedes a stale manual override, and
  also triggers a re-sync of that member's `pmpro_expected` values), or
  explicitly by an admin action on the User Assignment Details page
  ("clear override" per row).
* Only this table and the actual Groups.io add/remove/read calls (section
  8) touch Groups.io directly for User Assignment purposes — the List,
  Details, and Add Groups pages (section 12) all read from this local
  table, never live-aggregating across subgroups on a page load.
* **Owner-removal safeguard — added 2026-08-12**: `sync_subgroup()` now
  also captures each member row's `mod_status` field from
  `get_members()`'s response (confirmed via the live API reference —
  `sub_modstatus_owner` identifies the group's owner, distinct from
  `sub_modstatus_moderator`/`sub_modstatus_none`) into the new
  `is_owner` column. The User Assignment Details page (section 12) uses
  this to explicitly refuse to queue removing the owner from the
  *parent* group specifically — not from an individual subgroup, which
  remains allowed — since removing the owner from the parent group per
  section 12's own existing semantics removes them from BITS' entire
  Groups.io presence, an outcome that should never be reachable via a
  UI action regardless of what Groups.io's own API would actually do if
  asked (that behavior is unverified and out of scope for this
  safeguard to depend on — the block applies unconditionally, not only
  when the live API would also reject it).
* **Schema self-healing — added 2026-08-13**: `register_activation_hook`
  is not a fully reliable guarantee that `MemberIndex::create_table()`
  actually runs. Confirmed via direct reproduction on a from-scratch
  `wp-env` instance (matching CI's own always-fresh container setup):
  across two otherwise identical fresh-bootstrap runs of the same setup
  command sequence, one left `bits_groupsio_member_index` missing
  entirely after `wp plugin activate` reported success, while
  `bits_groupsio_audit`'s own `dbDelta()` call (the *first* of the two
  `register_activation_hook` callbacks registered in
  `group-manager.php`) succeeded both times. No PHP error or warning was
  ever produced by either run, even with `WP_DEBUG`/`SCRIPT_DEBUG` on —
  `dbDelta()` does not throw or otherwise surface a failure to create a
  table, so nothing in application code could previously have caught
  this. Manually re-running `create_table()`, or a plain WP-CLI
  deactivate/reactivate cycle against an already-provisioned install,
  always succeeded — the gap is specific to first-time activation
  timing on a brand-new database, not the SQL or the dbDelta call
  itself. Rather than continue chasing the exact MySQL-level trigger
  (expensive to reproduce — each fresh-bootstrap cycle took several
  minutes, and the race didn't reproduce every time), `MemberIndex` now
  self-heals: on every `plugins_loaded` (not only at activation), it
  checks a stored schema-version option against `SHOW TABLES`
  cheaply — one indexed lookup, not a `dbDelta()` call on every request
  — and re-runs `create_table()` if the table is missing or the stored
  version is stale. This is a standard defensive pattern for exactly
  this class of problem (activation hooks are known to be unreliable
  across various hosting/multisite/container scenarios) and fixes the
  symptom regardless of the precise root cause.

### 7. Audit Log Recording — `AuditLog::record()`

**New 2026-08-06.** `includes/AuditLog.php` currently only owns the
`bits_groupsio_audit` table's schema and creation (per its own docblock:
"Recording/reading audit entries is added in later phases alongside the
sync engine that produces them") — no code anywhere actually writes a row
to it yet. This section is that "later phase," scoped specifically to what
section 8's queued execution engine needs.

* `AuditLog::record( string $action, string $outcome, string $target_email, string $subgroup_id, int $user_id, string $api_response_detail = '' ): void`
  (exact signature decided at implementation time, matching the table's
  existing columns: `action`, `outcome`, `target_email`, `subgroup_id`,
  `user_id`, `api_response_detail`, `created_at`) — a straightforward
  `$wpdb->insert()` wrapper, consistent with this project's existing
  direct-`$wpdb`-write convention elsewhere (e.g. `LevelMandatoryGroups`,
  `Settings`).
* Called by section 8's queued job for every add/remove attempt, on both
  success and failure outcomes — not just failures.
* No audit log *viewer* UI in this pass (see section 2's out-of-scope
  list) — this section only makes recording real; reading/displaying
  entries is Phase 7's "per-member audit view."

### 8. Queued Execution Engine & Admin Notifications

**New 2026-08-06.** The first real implementation of issue #50's design,
scoped to User Assignment's add/remove actions (section 12). Subgroup
Management's create/update/delete actions remain synchronous (section 2)
— this queuing is specific to User Assignment, where bulk actions across
potentially many subgroups make a fully synchronous request impractical.

* **Instant confirmation**: submitting an Add, Remove, "Remove Selected,"
  or "Add Selected" action returns immediately with a "queued" notice —
  no synchronous Groups.io API call happens inside that request. A bulk
  action queues one job per `(member, subgroup)` pair, not one job for the
  whole batch, since `direct_add()`/`remove_member()` are themselves
  single-subgroup/single-target operations.
* **Background execution**: each queued job is an Action Scheduler action
  that calls the actual `direct_add()`/`remove_member()`. **Resolved
  2026-08-07**: Action Scheduler has no built-in automatic-retry-on-failure
  behavior of its own (a failed action is simply marked `failed`, once);
  retries are implemented manually — the action's args carry an `attempt`
  counter (starting at 1), and on a caught API/transport exception with
  `attempt < 3` the job reschedules itself as a new single Action Scheduler
  action (`as_schedule_single_action()`) with `attempt` incremented and a
  short fixed backoff delay (1 minute), rather than immediately re-running
  in the same request. At `attempt === 3` a further failure is exhaustion
  — see below. A `remove` job resolves its target via the local index's
  new `member_info_id` column (see section 6) rather than an extra live
  Groups.io lookup.
* **On success** (first attempt or after a retry): the job updates the
  local member-index row (section 6) to reflect the new actual state,
  writes the sticky-override flag (`override_type`/`override_by`/
  `override_at`), calls `AuditLog::record()` (section 7) with a success
  outcome, and raises a success notification (below).
* **On exhaustion** (all 3 retries failed): the job calls
  `AuditLog::record()` with a failure outcome and raises a failure
  notification (below). The local index row is left unchanged (still
  reflecting the last-known actual state, not the attempted-but-failed
  change).
* **Admin notifications**: WordPress has no built-in persistent
  notification center — a page-load-only `admin_notices` hook can't
  represent an outcome that becomes known *after* the request that
  triggered it, since the job runs asynchronously. **Resolved
  2026-08-07**: a single `wp_options` row (autoloaded `false`, e.g.
  `bits_groupsio_admin_notifications`) storing a small array of pending
  notification records (`id`, `type` — `success`/`failure`, `message`),
  rather than a new dedicated table — this queue is small (only currently
  pending, undismissed notices) and short-lived by nature, unlike the
  audit log or member index, so a table's schema/indexing overhead isn't
  warranted. This mechanism:
  * Records one notification per job outcome — both success and failure,
    per explicit direction, not just failure.
  * Renders each pending notification as its own distinct, individually
    dismissible (`notice is-dismissible`) admin notice on the next admin
    page load — multiple notifications display as multiple separate
    notices, never merged or collapsed into one, even when several jobs
    from the same bulk action complete around the same time. Success and
    failure are distinguished in the notice's text (not by CSS notice
    class/color alone), per the accessibility acceptance criterion.
  * A notification is cleared once the admin dismisses it, via a small
    `wp_ajax_bits_groupsio_dismiss_notification` handler (nonce-checked)
    that removes that one record from the option's array server-side —
    WordPress core's own `is-dismissible` notices only hide the DOM node
    client-side and don't persist dismissal, which isn't sufficient here
    since a plain page-load hides nothing on its own; this queue's records
    must be actively removed once seen, not merely visually hidden.
* **Auto-add to parent group — added 2026-08-12**: `queue_add()` now also
  queues an add to the configured parent group whenever the member being
  added to a subgroup isn't already a current member of the parent
  ("current" per the same definition `MemberIndex::get_member_groups()`
  uses elsewhere — a row exists and doesn't carry `override_type = 'removed'`).
  Applies everywhere `queue_add()` is called (today, only the Add Groups
  view's "Add Selected" - not limited to that one caller, so any future
  add path automatically gets the same behavior). A member can't
  meaningfully belong to one of BITS' Groups.io subgroups without also
  belonging to the parent group itself.
  * The parent-membership check and the parent subgroup's own numeric id
    are both resolved purely from the local index
    (`MemberIndex::is_currently_in_group()`/`find_group_by_slug()`) — no
    live Groups.io call happens inside `queue_add()`, consistent with
    section 8's existing "no synchronous Groups.io API call happens
    inside that request" principle. If the parent's own row has never
    been locally indexed yet (only possible before the first sync has
    ever run against a fresh install), the parent id can't be resolved
    locally and the auto-add is skipped rather than making a live call
    to look it up — the next scheduled `sync()` run corrects this
    regardless, and a brand-new install with no prior sync is not a
    state any admin action happens in practice.
  * Guarded against infinite recursion by construction, not a separate
    check: the parent-add path only ever triggers when the subgroup
    being added is *not itself* the parent, so the recursive
    `queue_add()` call it makes (for the parent) immediately fails that
    same condition and returns without recursing further.
  * Does not change the "N group addition(s) queued" notice count on the
    Add Groups view — the auto-queued parent add is a transparent
    implementation detail of ensuring valid Groups.io membership, not an
    action the admin explicitly requested tracking for.
* **Manual "Sync" control — added 2026-08-11**: a small "Sync" button
  (nonce-protected POST, no parameters) appears at the top of every
  Subgroup Management view (List, Create, Details) and every User
  Assignment view (List, Details, Add Groups), letting an admin force
  Action Scheduler to process any currently-due queued jobs immediately
  from within the request, rather than waiting on WP-Cron's own timing.
  New `QueuedExecutionEngine::process_due_jobs(): int`, a thin wrapper
  around Action Scheduler's own `ActionScheduler_QueueRunner::instance()->run()`
  (the same call WP-Cron itself uses to process due actions) — this
  project doesn't reimplement queue-draining logic, it only exposes a
  manual trigger for Action Scheduler's own. Submitting redirects back to
  the same view with a notice: "N queued action(s) processed." or "No
  queued actions were due." Not scoped to the current page/member — it
  processes whatever is globally due, since Action Scheduler has no
  per-page/per-member job index to filter by, and scoping would add
  complexity for no real benefit (an admin who wants to confirm one
  member's action completed can just re-check that member's Details page
  afterward). On Subgroup Management's pages this only affects *other*
  pending queued jobs (e.g. from User Assignment) today, since Subgroup
  Management's own create/update/delete actions remain synchronous
  (until #50); the control is added there now anyway, per explicit
  direction, so it's already in place once #50 makes those actions
  queued too.
* **Subgroup Management CRUD queuing — added 2026-08-12 (#50/#51)**: a
  new, separate `SubgroupExecutionEngine` (deliberately not folded into
  `QueuedExecutionEngine` above — its audit/notification shape is
  meaningfully different: a subgroup action has no target member email,
  and per explicit direction below, it notifies on failure only, not
  every outcome the way User Assignment's engine does) queues
  Subgroup Management's create/update/delete actions instead of running
  them synchronously inside the request.
  * **Why, and why uniformly across all three actions**: the original
    motivation (Copilot's review of #43) was that a single immediate
    read-back check against `get_subgroups()` can misreport a real
    success as a failure, since that specific listing endpoint is the
    one confirmed eventually-consistent by the live integration test's
    own polling (`assert_eventually()`, section 5) — `create_subgroup()`'s
    and `update_subgroup()`'s own write-call responses are *not*
    eventually-consistent (the response object itself already reflects
    the change, per Groups.io's own documented contract and this
    project's integration test, which trusts `create_subgroup()`'s
    returned `id` directly with no polling) - only the separate
    `get_subgroups()`/`get_members()`-style listing calls were ever
    observed to lag. On its own, that would mean only Delete strictly
    needs queued retry (confirming an absence has no "here's the
    result" object to trust). Explicitly decided with the primary
    contributor to queue Create and Update too regardless: a flooded
    network or other transient failure could make the write call itself
    fail, and a queued job retries that automatically without requiring
    the admin to notice and resubmit. So all three actions are queued
    uniformly, for two different reasons - Delete for genuine read-back
    eventual consistency, Create/Update for automatic retry of the
    write call itself.
  * **What stays synchronous**: the pre-queue validation each
    `process_*()` method already does - `process_create()`'s empty-name
    check, `process_update()`'s id/slug re-validation against a fresh
    listing and its changed-fields diff (an unchanged no-op update still
    returns `updated` immediately, nothing queued), and
    `process_delete()`'s existing-vs-already-gone pre-check (an
    already-gone target still returns `deleted` immediately, per its
    existing idempotent-tolerance reasoning). None of this needs
    queuing - it's local reasoning against an already-fetched listing,
    not a Groups.io write.
  * **Immediate response**: once a `process_*()` method decides there's
    an actual change to make, it queues the job and returns
    immediately - three new notice codes, `create_submitted`,
    `update_submitted`, `delete_submitted` (all `success`-type,
    "your request has been submitted and is being processed" framing),
    replacing the old synchronous read-back-failure notice text for the
    *not-yet-confirmed* case specifically. A definitive, immediate
    validation failure (e.g. the pre-queue checks above) is unaffected
    and still reported synchronously as today.
  * **Queued execution, per attempt** (`SubgroupExecutionEngine::execute()`,
    same `MAX_ATTEMPTS = 3` / `RETRY_DELAY_SECONDS` manual-reschedule
    pattern as `QueuedExecutionEngine`, since Action Scheduler has no
    automatic retry of its own):
    * *Create*: invalidates the expected slug's `SubgroupIdCache` entry,
      then calls `create_subgroup()`. On success, validates the
      description directly against the response object - no separate
      `get_subgroups()` read-back call. If a title was submitted, calls
      `update_subgroup()` for it and validates title/description
      against *that* response object instead (a fresher one). On a
      retried attempt (`attempt > 1`) that hits a `name exists`-shaped
      error, treats it as idempotent success (a prior attempt already
      created it, but the response was lost to the same transient
      failure that's being retried) - looks the subgroup up by slug via
      one `get_subgroups()` call and continues from there, rather than
      reporting a spurious failure for a create that actually succeeded.
    * *Update*: invalidates the old and new slug's `SubgroupIdCache`
      entries (if renaming), then calls `update_subgroup()` with the
      already-computed field diff. Validates every changed field
      directly against the response object - no separate read-back
      call.
    * *Delete*: calls `remove_subgroup()` (already idempotent-tolerant
      of `group_not_found`, per the existing synchronous logic, carried
      over unchanged), then confirms absence via one `get_subgroups()`
      call. Still listed → this attempt is treated as not-yet-confirmed
      and retried; genuinely the one case among the three where the
      outer retry loop's backoff (a full minute between attempts) is
      also functioning as the polling the eventual-consistency problem
      needs, since there's no result object to trust instead.
    * Any exception (API or transport) on an attempt with retries
      remaining reschedules per the existing pattern; on final
      exhaustion, or a not-yet-confirmed Delete after `MAX_ATTEMPTS`,
      records the outcome.
  * **`SubgroupIdCache` invalidation timing - resolved**: happens inside
    `execute()`, at the start of each attempt, not once synchronously at
    queue time - a retry after a transient failure must not risk serving
    a stale cache entry from before that attempt, so invalidation is
    re-applied on every attempt, not just the first.
  * **Notification policy - confirmed with the primary contributor**: no
    success notice - the immediate `*_submitted` response already told
    the admin the action was accepted, and a second notice later just
    confirming it worked would add noise for the common case. Only
    final exhaustion calls `AdminNotifications::add( 'failure', ... )`,
    reusing the exact same persisted-notification mechanism section 8
    already established for User Assignment, since the "admin may not
    still be on the page when the queued job finishes" problem is
    identical here.
  * **Audit logging - new**: every queued outcome (success and failure
    alike, unlike the notification policy above - the audit trail's own
    purpose is a complete record, not just alerting) now calls
    `AuditLog::record()` with `subgroup_create`/`subgroup_update`/
    `subgroup_delete` as the action, `target_email` left empty (not
    applicable, same convention `Settings`' own `settings_update` audit
    entries already use per `security.md` section 3), `subgroup_id` set,
    and `api_response_detail` summarizing what changed or the failure
    reason. The prior synchronous `process_*()` methods never wrote to
    the audit log at all - a real gap this change also closes, since
    every other Groups.io-touching action in the plugin already does.
* **Action Scheduler status tracking — added 2026-08-18**: the "Sync"
  button's notice ("N queued action(s) processed." / "No queued actions
  were due.") is not a reliable signal that a job just queued in the
  *same* admin session has actually finished. Investigated and documented
  in GitHub Discussion #110: Action Scheduler claims due actions
  exclusively (`ActionScheduler_DBStore::claim_actions()`'s
  `WHERE claim_id = 0 AND scheduled_date_gmt <= NOW() ... FOR UPDATE
  SKIP LOCKED`), and it runs itself independently of this plugin's own
  manual trigger — a real WP-Cron event (`action_scheduler_run_queue`,
  every minute) and a separate async dispatcher hooked to WordPress's
  own `shutdown` action can both claim and run a just-queued job before
  the admin's next "Sync" click gets to it. When that happens, the Sync
  click's own `process_due_jobs()` call correctly finds nothing left to
  claim and reports "No queued actions were due" — technically true, but
  misleading, since the job already ran (or is mid-flight) via that
  other, invisible request. Confirmed as a known, upstream-documented
  class of behavior (not unique to this plugin's usage), not something
  Action Scheduler itself is expected to eliminate — see the discussion
  for the two related upstream issues cited
  (woocommerce/action-scheduler#457, #793) and why polling the
  *specific* queued job's own status is the standard way around it,
  rather than trusting the queue runner's aggregate processed-count.
  * **New `includes/ActionSchedulerClient.php`**: the one seam that
    calls Action Scheduler's `as_*()` functions directly — currently
    that call (`as_schedule_single_action()`) is inlined in
    `QueuedExecutionEngine::schedule()`, with no equivalent status-read
    seam existing anywhere. Single responsibility, matching
    `CLAUDE.md`'s one-class-one-purpose rule: this class owns nothing
    but "talk to Action Scheduler," not queuing/retry logic itself
    (which stays in `QueuedExecutionEngine`/`SubgroupExecutionEngine`).
    * `schedule( array $job, int $timestamp ): int` — wraps
      `as_schedule_single_action()`, returning the scheduled action's
      ID (0 if Action Scheduler is unavailable, matching the existing
      `function_exists( 'as_schedule_single_action' )` guard's current
      no-op behavior).
    * `get_status( int $action_id ): ?string` — wraps Action
      Scheduler's own status lookup (`ActionScheduler_Store::instance()
      ->get_status( $action_id )`), returning one of Action Scheduler's
      own status strings (`pending` / `in-progress` / `complete` /
      `failed` / `canceled`), or `null` if the action id is unknown or
      Action Scheduler isn't available.
    * `is_finished( int $action_id ): bool` — convenience wrapper;
      `true` for `complete`/`failed`/`canceled`, `false` for
      `pending`/`in-progress` or an unknown/unavailable id.
  * **`QueuedExecutionEngine::queue_add()`/`queue_remove()` now return
    the queued action's id** (`int`, 0 on failure to schedule) instead
    of `void`, sourced from `ActionSchedulerClient::schedule()`'s return
    value. `queue_add()`'s auto-queued parent-add (this section, above)
    also returns its own id from `maybe_queue_parent_add()`, but per the
    existing "does not change the visible queued-count" precedent
    already established for that auto-add, its id is not surfaced to
    the caller — only the id of the action the admin's own click
    directly requested is tracked.
  * **`UserAssignmentPage::process_add_selected()`/
    `process_remove_selected()`** collect the returned action ids
    (one per checked group) into an array instead of only a count, and
    pass that array through the post-submit redirect as a query arg
    (e.g. `bits_pending_actions=123,124,125`) — a plain list of opaque
    integers, not user-supplied or sensitive, so a query arg is
    sufficient; no new transient/session storage needed.
  * **Sync notice becomes per-tracked-job, not just a raw count**:
    `process_sync()` reads any `bits_pending_actions` ids present in the
    request (round-tripped from the view that submitted Sync, the same
    way `sync_view`/`member` already round-trip today), and after
    calling `QueuedExecutionEngine::process_due_jobs()` checks each
    tracked id's status via `ActionSchedulerClient::is_finished()`. If
    every tracked id is finished, the existing "N processed" /
    "no queued actions were due" notice logic is unchanged (nothing new
    to report). If one or more tracked ids are *not* yet finished
    (claimed and running elsewhere, or still pending), the notice
    instead reads "Still processing — click Sync again in a moment" (or
    equivalent copy, finalized at implementation time with a screen
    reader pass) rather than the previous binary framing that could
    read as "nothing happened" when the job is actually mid-flight.
    Once a tracked id's job actually completes (whether via this click,
    a later click, or Action Scheduler's own independent trigger),
    `bits_pending_actions` naturally stops being passed forward (each
    view's own Sync form only round-trips ids relevant to the page the
    admin is currently viewing, sourced fresh from that page's own
    render — not accumulated indefinitely across unrelated page visits).
  * **Scope**: this addendum covers `QueuedExecutionEngine`'s
    add/remove jobs and the User Assignment pages' Sync control only.
    `SubgroupExecutionEngine` (this section, above) already has a
    different notification policy (failure-only, no success notice) and
    is not affected by this change — its own Sync-button interaction is
    unchanged unless a future amendment extends this same pattern to
    it.

### 9. Admin Pages: Menu Structure

* A single new top-level WordPress admin menu item, "GroupsIO Management,"
  registered via `add_menu_page()`, with submenu pages registered via
  `add_submenu_page()`:
  1. User Assignment (default landing page for the top-level menu click).
  2. Feature Controls.
  3. Subgroup Management.
  4. **Added 2026-08-12**: Plugin Configuration — see section 15.
* Each page is its own class under `includes/Admin/` (new subdirectory,
  since this is the first admin-page-per-class split in the codebase;
  existing `Settings.php` stays where it is per section 10 below), following
  the single-responsibility-per-class rule in `CLAUDE.md`.
* All pages follow the accessible-admin-UI patterns already
  established in `Settings.php` and documented in
  `.github/instructions/frontend.instructions.md`: native `<label for>`,
  `aria-describedby` field descriptions, autofocus on the first field only,
  correct context-specific escaping, and explained-disabled-controls.

### 10. Page: Feature Controls

* Renames and relocates the existing Phase 1 `Settings` admin page
  (global mandatory groups, grace period, log retention policy, kill
  switch) under the new "GroupsIO Management" menu, replacing its current
  standalone menu placement. The underlying `Settings` class and its
  option storage are not rebuilt — only the menu registration and page
  title/labels change to reflect the new location.
* No new functionality in this pass beyond the relocation itself.

### 11. Page: Subgroup Management

**Redesigned 2026-08-05**, per the primary contributor's explicit direction to prioritize a screen-reader-friendly, low-density layout over a single all-in-one page. Three distinct views instead of one combined list/create/rename/delete page:

* **List view** (default, `?page=bits-groupsio-subgroup-management`):
  * A count line ("N subgroups provisioned"), from the length of `GroupsIoApiClient::get_subgroups()`'s result.
  * The parent group's own full address ("Parent group: `main@perception-is-all.groups.io`"), fetched via `GroupsIoApiClient::get_group()`'s `email_address` field.
  * A single link to the Create view.
  * A plain unordered list of subgroups, one link per subgroup, each link's visible text *and* accessible name being that subgroup's full `email_address` (e.g. `test-group-3@perception-is-all.groups.io`) - unique and unambiguous to a screen reader without needing a per-row `aria-label`, unlike the prior design's identically-labelled per-row controls. Member count shown as adjacent non-link text next to each entry, not folded into the link's accessible name.
* **Create view** (`?page=bits-groupsio-subgroup-management&view=create`):
  * Three fields: Name (required - the segment that determines the subgroup's address/URL, per `create_subgroup()`), Title (optional, cosmetic display label only - its field description explicitly states it does not affect the address), Description (optional).
  * `createsubgroup` has no `title` parameter (confirmed against the live docs, section 4.1) - if Title is provided, creation is `create_subgroup()` followed immediately by an `update_subgroup()` call setting only `title`.
  * One "Create" button. **Changed 2026-08-12 (#50)**: the write and its confirmation are now queued (`SubgroupExecutionEngine`, section 8) rather than synchronous - submitting redirects to the List view immediately with a `create_submitted` notice, not a confirmed-success notice. `SubgroupIdCache` invalidation and refresh happens per queued attempt (section 8), not synchronously at submit time.
* **Details view** (`?page=bits-groupsio-subgroup-management&view=details&subgroup_id=N`):
  * Editable Name/Title/Description fields, pre-filled with the subgroup's current values (fetched fresh, matched against the submitted id per the existing id/slug re-validation pattern from the prior design - still required, since this field set is still reachable via an editable hidden/query id).
  * Below the editable fields: the live member list (read-only), moved here from the old design's per-row "view members" expansion - `GroupsIoApiClient::get_members()`, always read fresh, never cached.
  * "Update" button: calls `update_subgroup()` with only the fields that actually changed (partial update - `name` change is a true rename per section 3/4.4 above and carries the existing "does not update any level's mandatory-groups list" warning; `title`/`desc` changes are purely cosmetic). **Changed 2026-08-12 (#50)**: queued (`SubgroupExecutionEngine`, section 8), same as Create - an unchanged submission (no fields actually differ) is still detected and reported synchronously as `updated` with nothing queued, since that's local reasoning, not a Groups.io write.
  * "Delete" button: reveals an inline confirmation (warning text + "Yes, delete"/"Cancel") on the same page - no separate confirmation page/step, per the same low-density-screen goal. **Changed 2026-08-12 (#50)**: confirmed delete queues the actual `remove_subgroup()` call and its absence-confirmation (`SubgroupExecutionEngine`, section 8) rather than performing and read-back-verifying it synchronously - redirects to the List view immediately with a `delete_submitted` notice. The already-gone pre-check (an already-deleted target) is unaffected and still resolves synchronously as `deleted`.
  * All Groups.io API errors from the pre-queue validation steps above surface in plain language, consistent with the prior design; a queued action's eventual failure instead surfaces via the persistent `AdminNotifications` notice (section 8), since the admin may no longer be on this page by the time it's known.
  * **Added 2026-08-12**: an "Add member" control, below the live member list. A search box matching against `MemberIndex`'s existing tracked members by name or email (the same membership-driven model User Assignment already uses - existing PMPro members only, never an arbitrary typed-in email address, per direction), a result list of matches with a checkbox each, and an "Add Selected" button. Submitting queues an add job per selected member for this subgroup via `QueuedExecutionEngine::queue_add()` (section 8) - the same async queued pattern as User Assignment's own Add Groups view, including its existing auto-add-to-parent-group behavior if a selected member isn't already in the parent. Because this is queued rather than synchronous, the member doesn't appear in the live member list above until the job actually runs - the page's existing "Sync" button (section 8) can force this immediately rather than waiting on Action Scheduler's own timing. This is the reverse direction of User Assignment's existing member-first Add Groups flow (start from the group, not the member); both write through the same `queue_add()` path, so there is exactly one place the actual add logic lives.

### 12. Page: User Assignment

**Redesigned 2026-08-06**, expanding the original single-page Add/Remove/
Clear-override concept into three distinct low-density views, mirroring the
List/Details pattern already established for Subgroup Management (section
11), plus bulk actions and asynchronous queued execution (section 8). The
original reconciliation concept — the PMPro-expected-set comparison and the
sticky-override flag — still applies; this redesign changes how the page is
organized and how actions execute, not what the page is fundamentally for.

* **List view** (default, `?page=bits-groupsio-user-assignment`):
  * A paginated table drawn from the local member index (section 6): each
    row shows the member's Name, Email, and total group count (parent +
    subgroups).
  * A search box + button: matches against member name, email, or subgroup
    name/slug — a query matching a subgroup filters the list to members
    belonging to that subgroup.
  * Each member's name links to their Details view.
* **Details view** (`?page=bits-groupsio-user-assignment&view=details&member=...`),
  **expanded 2026-08-09** with the concrete query/action semantics below,
  confirmed with the primary contributor ahead of #61's implementation:
  * A paginated list of the member's current subscribed groups (parent +
    subgroups), each row displaying the group as "Title (namespace)" (e.g.
    "Sustaining Members (`perception-is-all+sustaining-members`)"), a
    PMPro-expected indicator (flags a disagreement between actual and
    PMPro-expected membership — never conveyed by color alone), the
    current sticky-override state if one is set, and a checkbox.
  * Backed by two new `MemberIndex` query methods,
    `get_member_groups( string $email, int $page, int $per_page, string $search = '' )`
    and `count_member_groups( string $email, string $search = '' )`,
    mirroring the List view's `get_members_page()`/`count_members()`
    (section 6). Both exclude any row carrying `override_type = 'removed'`
    — an explicitly-removed row no longer counts as "currently
    subscribed" even before the next hourly sync corrects it, since a
    member the admin just removed should not still appear as subscribed on
    this same page. Search matches `subgroup_slug`/`subgroup_title`, same
    convention as the List view's subgroup-name matching.
  * A search box + button filtering this member's own group list by
    subgroup name or title.
  * "Remove Selected" button: queues a remove job (section 8,
    `QueuedExecutionEngine::queue_remove()`) for each checked non-parent
    group immediately. **If the parent-group row is among the checked
    rows**, submitting shows an inline confirmation step first (same
    pattern as Subgroup Management's delete confirmation — warning text
    plus "Yes, remove"/"Cancel" on the same page, no separate
    confirmation page) explaining that removing the parent group removes the member
    from all of BITS' Groups.io presence, not just one list — confirmed
    2026-08-09 that this action is intentionally allowed from this page,
    but only behind that explicit warning. Non-parent rows checked
    alongside the parent still queue immediately; only the parent row's
    job waits on the confirmation. **Exception, added 2026-08-12**: if
    the member is the group's owner (`MemberIndex`'s new `is_owner`
    column - section 6), the parent-group row is refused outright
    rather than shown the confirmation step - a distinct
    `owner_removal_blocked` notice explains why, and no job is ever
    queued for it. Re-checked independently both when "Remove Selected"
    is first submitted and again if the confirmation step's own "Yes"
    is submitted, rather than trusted from the first check alone.
    Non-parent rows checked alongside the owner's parent row still
    queue normally - only the parent row is blocked.
  * "Clear override" control per row carrying an override flag, releasing
    it back to normal automated management. Synchronous (a normal POST
    handled on this page's own `load-{hook}` action, redirecting back to
    the Details view with a notice), not queued — confirmed 2026-08-09
    that this is a pure local write to the index row
    (`MemberIndex::clear_override( string $email, int $subgroup_id )`, new
    — the single-row counterpart to the existing
    `clear_overrides_for_user()`, which clears every row for a user on a
    PMPro level change) with no Groups.io API call and nothing to
    meaningfully retry, so routing it through the queued execution engine
    would add complexity for no benefit.
  * A link to the Add Groups view.
* **Add Groups view** (`?page=bits-groupsio-user-assignment&view=add-groups&member=...`):
  * A paginated checkbox list of every group (parent + subgroups) the
    member is *not* currently in, per the local index, each displayed as
    "Title (namespace)" — same format as the Details view.
  * A search box + button, same subgroup name/title matching as the
    Details view.
  * "Add Selected" button: queues an add job (section 8) for each checked
    group.
  * Redirects to the Details view on submission — the actual adds happen
    asynchronously (section 8); the page confirms the jobs were queued,
    not that they've completed.
* Suspend remains deferred to Phase 6 (section 4) — not built in this pass.

### 13. Testing Approach

* `GroupsIoApiClient::create_subgroup()`/`remove_subgroup()`: unit-tested
  against mocked HTTP responses (success, duplicate/conflict error,
  not-found error), same `pre_http_request` mocking convention as the rest
  of the client.
* Local member-index sync job (section 6): unit-tested against mocked
  `get_subgroups()`/`get_members()` responses, asserting correct upsert
  behavior and correct `pmpro_expected` computation for a range of
  level/global-mandatory-group combinations.
* `AuditLog::record()` (section 7): `WP_UnitTestCase`-based unit tests
  asserting a row is written with the expected columns, against the real
  WordPress test database.
* Queued execution engine and admin notifications (section 8): unit-tested
  for job scheduling/dispatch, retry-count behavior, and the
  distinct-per-outcome notification write path — Groups.io calls
  themselves mocked, same convention as the rest of the client's tests.
* `SubgroupExecutionEngine` (section 8, **added 2026-08-12 (#50)**):
  unit-tested the same way `QueuedExecutionEngine` already is - job
  scheduling/dispatch, `MAX_ATTEMPTS` retry behavior, the
  idempotent-tolerant retry path for Create (`name exists` on
  `attempt > 1`), and the failure-only notification/every-outcome-audit
  split (unlike `QueuedExecutionEngine`'s every-outcome notification
  policy - a real behavioral difference worth its own explicit test
  coverage, not just relying on `QueuedExecutionEngine`'s tests to imply
  correctness here too). `update_subgroup()` itself gets new live
  integration coverage (#51): the existing
  `SubgroupLifecycleIntegrationTest` (section 5) is extended to exercise
  a rename (`name`), a title change, and a description change against a
  throwaway subgroup, each confirmed via `assert_eventually()`
  read-back against the real test group - `update_subgroup()` was
  previously only exercised against canned unit/E2E mock responses that
  already encode the same `name`-vs-`title` assumption they'd be
  checking, not the real API.
* Sticky-override flag read/write logic (now part of the member-index
  table, section 6): `WP_UnitTestCase`-based unit tests against the real
  WordPress test database, per existing convention.
* The admin page classes (**five as of 2026-08-12**, across Feature
  Controls, Subgroup Management, User Assignment's three views, and
  Plugin Configuration - section 15): unit-tested for their
  data-handling logic (form processing, nonce verification, capability
  checks) using `WP_UnitTestCase`; a manual screen-reader pass by the
  primary contributor is required before any page is considered done, per
  `CLAUDE.md`'s Accessibility by Design section — this is not satisfied by
  automated tests alone.
* The permanent integration test (section 5) runs automatically in CI on
  every pull request, gated on the `groupsio-test-group` environment, per
  `testing-standard.md` section 4 and `ci.md` section 3.2.

### 14. Exit Criteria (per `phase-plan.md`)

Reproduced from `phase-plan.md` for traceability — see that document as
the authoritative source if the two ever diverge:

* **Phase 2 (amended)**: `create_subgroup()`/`remove_subgroup()` are
  unit-tested and live-verified; the suspend-state investigation is
  resolved one way or the other; the permanent integration test passes
  automatically in CI against the test group.
* **Phase 3**: an admin can reach all three GroupsIO Management pages; the
  local member index (section 6) is populated and queryable, with
  `pmpro_expected` correctly computed; `AuditLog::record()` (section 7)
  writes a real entry for every add/remove attempt; an admin can add,
  remove, and bulk-remove/bulk-add a test member's subgroup assignments
  from User Assignment's List/Details/Add-Groups views, each action
  completing asynchronously via the queued execution engine (section 8)
  with a distinct success or failure notification and the sticky-override
  flag verifiably respected by a subsequent reconciliation run (once
  Phase 6 exists to test that end-to-end — until then, verify the flag is
  at least correctly written and readable); can save/retrieve operational
  settings from Feature Controls; and can create, list, update, and delete
  a subgroup and view its membership from Subgroup Management — all
  verified against the test group. (Suspend is deferred to Phase 6 — see
  section 4.)
* **Phase 3, 2026-08-12 amendment**: an admin can reach and use the
  Plugin Configuration page (section 15); the parent group falls back
  correctly to the database-backed setting when `GROUPS_IO_PARENT_GROUP`
  is undefined, and to the constant (read-only in the UI) when it is;
  the activation notice appears when no parent group is configured by
  either means and disappears once one is; and an admin can add an
  existing tracked member to a subgroup directly from that subgroup's
  Details view (section 11), the add completing asynchronously via the
  queued execution engine exactly like every other add in the plugin —
  all verified with a screen reader pass.
* **Phase 3, further 2026-08-12 amendment (#50/#51)**: Subgroup
  Management's Create/Update/Delete each complete asynchronously via
  `SubgroupExecutionEngine` (section 8) - an admin sees an immediate
  `*_submitted` notice on each, a genuine failure after `MAX_ATTEMPTS`
  surfaces as a persistent, dismissible `AdminNotifications` notice
  rather than a one-shot redirect notice, and a real success within
  those attempts produces no additional notice; `AuditLog::record()`
  writes a real entry (both outcomes) for every Subgroup Management
  create/update/delete attempt, closing the gap where these three
  actions never wrote to the audit log at all; and the live integration
  test (section 5) confirms `update_subgroup()`'s rename/title/desc
  behavior against the real test group, not just mocked responses — all
  verified against the test group, and the persistent notice verified
  with a screen reader pass.

### 15. Page: Plugin Configuration — Added 2026-08-12

Resolves the parent-group configuration gap raised when the primary
contributor requested an admin-level plugin configuration page: today
`GROUPS_IO_PARENT_GROUP` is `wp-config.php`-only (PRD section 3.1), which
means a fresh install with no `wp-config.php` edit yet has no working
parent group at all, and every one of `SubgroupIdCache`,
`SubgroupManagementPage`, `UserAssignmentPage`,
`QueuedExecutionEngine`, and `MemberIndex` independently duplicates the
same `defined( 'GROUPS_IO_PARENT_GROUP' ) ? GROUPS_IO_PARENT_GROUP : ''`
fallback pattern. This page centralizes that into one resolver and gives
an admin a way to set the value without editing `wp-config.php`, without
weakening the existing credential-handling policy (`GROUPS_IO_API_KEY`
stays constant-only — see `security.md` section 2's 2026-08-12
exception).

* **Precedence, confirmed with the primary contributor**: `GROUPS_IO_PARENT_GROUP`
  always wins if defined in `wp-config.php`. The database-backed setting
  is purely a fallback for when the constant is absent — it is never
  consulted if the constant is defined, and the admin page's field
  becomes a read-only display of the constant's value in that case
  (with explanatory text, matching the existing pattern for
  constant-driven read-only fields elsewhere in the plugin), not an
  editable field that would misleadingly imply it does anything.
* **New centralized resolver**: a single method (e.g.
  `Settings::get_parent_group(): string`, alongside `Settings`'s
  existing option-backed getters, or a new dedicated class if a
  `Settings` addition doesn't fit its existing responsibility - an
  implementation-level call) replacing the five duplicated
  `defined() ? GROUPS_IO_PARENT_GROUP : ''` call sites above. Returns
  the constant if defined, else the new `wp_option` (see below), else
  `''` (matching every existing call site's current empty-string
  fallback behavior when unconfigured, so nothing downstream needs to
  change how it handles "no parent group configured").
* **Storage**: a new option, `bits_groupsio_parent_group` (matching
  `Settings::OPTION_NAME`'s naming convention), holding the plain
  slug string. Not part of `Settings::OPTION_NAME`'s existing single
  serialized array — a standalone option, since it is conceptually a
  fallback for a constant, not an operational setting alongside grace
  period/kill switch/etc. (PRD section 3.2 already draws this same
  distinction).
* **Page UI** (`?page=bits-groupsio-plugin-configuration`, single view,
  no List/Details split needed - one setting):
  * If `GROUPS_IO_PARENT_GROUP` is defined: the parent group slug shown
    read-only, with field description text stating it's set via
    `wp-config.php` and not editable here.
  * If not defined: an editable text field pre-filled with the current
    `wp_option` value (empty if never set), plus a "Save" button. Saves
    via this page's own `load-{hook}` POST handling (matching every
    other page's "why not inside render()" convention - section 9/11
    already establish why), with a nonce, `manage_options` capability
    check, and a success notice on save.
  * No validation against live Groups.io on save (no API call from this
    page at all) - a mistyped value simply causes the same
    `group_not_found`-class errors elsewhere in the plugin that a wrong
    `wp-config.php` constant value would already cause today. This
    matches the existing risk model for a constant-driven value
    (`security.md` section 4's 2026-08-12 Threat Model entry).
* **Activation notice**: on `admin_init` (checked only on-load, no new
  activation hook needed), if the resolver above returns `''` (no
  parent group configured by either means), a persistent admin notice
  is shown on every wp-admin screen linking to the Plugin Configuration
  page, matching core WordPress's own standard undismissed-until-resolved
  admin-notice pattern (e.g. "no permalink structure set"). The notice
  does not appear once a parent group is configured by either means, and
  does not appear at all if `GROUPS_IO_PARENT_GROUP` is already defined
  (nothing to configure in that case). Recommended, lower-effort option
  from the choices discussed with the primary contributor - not a
  multi-step setup wizard.
* Follows the same accessible-admin-UI conventions as every other page
  (section 9).
