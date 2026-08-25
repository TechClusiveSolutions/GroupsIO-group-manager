# Group / Membership Index Design

## 1. Status

Design confirmed via [Discussion #118](https://github.com/TechClusiveSolutions/GroupsIO-group-manager/discussions/118). Tracked as parent issue #116, with child issues opened for schema creation, real-time writes, reconciliation, and admin UI, each carrying full acceptance criteria before implementation starts.

## 2. Problem statement

`MemberIndex` today is a single denormalized table keyed by `(email, subgroup_id)`, duplicating each subgroup's slug/title onto every member row. There is no independent record of "which groups currently exist," so a deleted subgroup's rows become permanently orphaned (#116) — nothing to diff against. This design splits group data, membership data, and member data into three tables with independent sync tracking.

## 3. Schema

### 3.1 `GroupIndex`

One row per group, including the parent group itself.

* `id` (PK), `subgroup_id` (unique, numeric Groups.io id)
* `slug`, `title`, `description`
* `is_parent` (bool)
* `sync_status` (`synced` / `pending` / `failed`) — reflects this group entity's own last create/update/delete/reconcile attempt against Groups.io
* `modified_at` — last time this row was written to, for any reason
* `synced_at` — last time this row was confirmed against Groups.io

### 3.2 `GroupMemberships`

Join table — one row per `(member, group)` pairing. Carries what `MemberIndex` carries today for that pairing:

* `id` (PK), `member_id` (FK → `MemberIndex`), `group_id` (FK → `GroupIndex`)
* `pmpro_expected`, `is_owner`
* `override_type`, `override_by`, `override_at`
* `member_info_id`
* `sync_status` (`synced` / `pending` / `failed`) — this specific membership's own last add/remove attempt against Groups.io
* `modified_at`, `synced_at`
* Unique key `(member_id, group_id)`

### 3.3 `MemberIndex` (repurposed)

One row per member (not per membership pairing). Does **not** hold PMPro-owned data such as mailing-list join preferences — that lives in PMPro's own member table and is out of scope for this plugin.

* `id` (PK), `user_id`, `email`, `display_name`
* PMPro membership level identifier
* Relevant Groups.io member-level fields affecting this plugin's own behavior — exact field list is an open item (section 8)
* `sync_status` (`synced` / `pending` / `failed`) — this member's own local record vs. PMPro
* `modified_at`, `synced_at`

## 4. Real-time write + reconcile pattern

Every mutation path writes its own table immediately, marked `pending`, then the relevant confirmation step flips it to `synced` or `failed`:

* `SubgroupExecutionEngine` create/update/delete → writes `GroupIndex` row immediately (`pending`), confirms via the write response (create/update) or a follow-up `get_subgroups()` check (delete, same as today), then sets `synced`/`failed`.
* `QueuedExecutionEngine` add/remove → writes `GroupMemberships` row immediately (`pending`), confirms via the job's terminal outcome, then sets `synced`/`failed`.
* PMPro level-change hook → writes `MemberIndex.sync_status` `pending` until the corresponding sync confirms it.

Failure after retries exhausted leaves the row at `failed` (not silently `pending` forever) — this is new: today a fully-exhausted retry only raises an `AdminNotifications` alert with no row-level trace.

## 5. Reconciliation (the #116 fix)

The hourly sync job, after a **fully successful** `get_subgroups()` fetch (not a partial/failed one — same bail-early safeguard as today):

1. Diffs `GroupIndex` rows against the live subgroup list; any `subgroup_id` no longer present is pruned (with an audit log entry), cascading to prune its `GroupMemberships` rows.
2. Diffs each group's `GroupMemberships` rows against that group's live `get_members()` response the same way member rows are reconciled today.

## 6. Admin UI

* **Group Details page**: status line showing `GroupIndex.sync_status`, and a live-computed member count (`COUNT()` against `GroupMemberships`, computed on page load and on Sync-button click — not stored).
* **Member Details page**: status line showing `MemberIndex.sync_status` (PMPro sync), plus each listed group membership shows its own `GroupMemberships.sync_status`.

## 7. Migration

`GroupIndex` and `GroupMemberships` are additive tables (new dbDelta-created tables, self-healing schema-version pattern matching `MemberIndex`'s existing convention). `MemberIndex`'s existing per-subgroup columns are dropped and replaced with the PMPro/Groups.io-member-field columns above — existing `(email, subgroup_id)` row data is migrated into `GroupIndex`/`GroupMemberships` on upgrade, not discarded.

## 8. Open items for the child issues

* Exact Groups.io member-level field list for `MemberIndex` (section 3.3).
* Every `MemberIndex` read method (~15) that `UserAssignmentPage`/`SubgroupManagementPage` call today needs a replacement query against the new schema — this is the bulk of the implementation work and should probably be its own child issue rather than folded into schema creation.
* Exact migration routine (dbDelta + one-time data-copy script) needs its own acceptance criteria.
