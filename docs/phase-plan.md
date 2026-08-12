# Phase Plan

## BITS Groups.io Membership Sync

Milestones are deliverable-based, not date-based. Each phase below is defined by its exit criterion; a phase is not complete until its exit criterion is demonstrably satisfied.

### Phase 0 — Planning & Design Finalization

No code is written in this phase.

* Exit criterion: **met**. The finalized PRD (`PRD.md`), this phase plan, the security document, and the testing standard document are all written and confirmed section by section with the primary contributor. All open items listed in PRD section 9 are resolved: Groups.io authentication and the add/remove/lookup contract are verified by live trial against the test group, PMPro's membership-level-change hook is confirmed, and WordPress.com Business Plan hosting constraints are confirmed against WordPress.com's own documentation.

### Phase 1 — Foundation & Plugin Scaffold

* Scaffold the plugin's main class structure.
* Implement `register_activation_hook` to run `dbDelta` for the `bits_groupsio_audit` table.
* Build the admin settings screen for the database-backed operational settings only (global mandatory groups, grace period, log retention policy, kill switch) — not credentials, which remain `wp-config.php` constants per PRD section 3.1.
* Build the level-specific mandatory groups meta box on the PMPro Edit Membership Level screen.
* Set up CI: linting and an initial (near-empty) PHPUnit run on every pull request.
* Exit criterion: the plugin activates cleanly on a WordPress install, the audit table exists, an admin can save and retrieve the operational settings and level-specific mandatory groups, and CI runs on every PR.

### Phase 2 — Groups.io API Client

* The Groups.io contract is already confirmed by live trial against the test group (see PRD sections 4.2, 4.3, 7, and 9) — this phase implements against that confirmed contract directly, with no remaining discovery work.
* Build the `GroupsIo_API_Client` class wrapping `directadd` (batched via repeated `subgroupid` fields) and `removemember` (one `member_info_id` per call, looped client-side for multi-subgroup removal), plus `getgroup`/`getsubgroups`/`getmembers` for lookups, using `wp_remote_request`.
* Build the subgroup-ID cache described in PRD section 4.3 (slug-to-`group_id` mapping, refreshed on `group_not_found` and periodically via reconciliation).
* Implement 429 handling (parse `Retry-After`, reschedule with jitter), 5xx/timeout retry via Action Scheduler's exponential backoff, and dispatch on the confirmed error `type` field: `unauthorized_error`/`inadequate_permissions` triggers the authentication hard-stop with admin alert, `group_not_found` triggers a skip-and-continue plus a cache invalidation.
* Unit-test the client against mocked HTTP responses only — no real network calls in the unit suite.
* Exit criterion: the client is unit-tested for all documented response/error paths, and has been manually exercised at least once against the existing test Groups.io group(s), successfully adding to and removing from a subgroup (already demonstrated during Phase 0 verification — this exit criterion is satisfied by that trial plus the corresponding automated unit tests once written).

### Phase 3 — GroupsIO Management Admin Pages

Approved as a scope expansion on 2026-07-22 (see `CLAUDE.md`'s Scope Discipline section) and amending Phase 2: full admin-side subgroup lifecycle management (create/list/update/delete subgroups via the Groups.io API, independent of the automated member sync built in later phases), manual admin override of individual member subgroup assignment for intervention when automation misbehaves, and a dedicated "GroupsIO Management" admin area exposing this as three distinct pages. Full design in `app/docs/subgroup-crud-and-admin-pages-design.md`.

* Amend Phase 2's client: add `GroupsIoApiClient::create_subgroup()`, `::remove_subgroup()`, and `::update_subgroup()`, plus a permanent CI-automated integration test exercising the full subgroup lifecycle (create → add → remove → delete) against the test group on every pull request.
* Build the "GroupsIO Management" top-level admin menu scaffold, and relocate the Phase 1 settings screen to become the "Feature Controls" page under it (storage and rendering unchanged — only its menu location and heading move).
* Build the Subgroup Management page: a List view (subgroup count, parent group address, every subgroup as a link to its Details view), a Create view, and a Details view (editable name/title/description, live member list, Update/Delete) — a low-density, screen-reader-friendly three-view layout, not a single combined page.
* Build the User Assignment page and the sticky manual-override flag data model: member lookup, per-subgroup Add/Remove actions with follow-up read-back verification, and a "clear override" action. Suspend is investigated and explicitly deferred: Groups.io's native `banmember` endpoint was found broken server-side by live trial on 2026-07-25, so suspend's eventual implementation (a `remove_member()` plus sticky-override-flag fallback) is folded into Phase 6's drift-reconciliation work instead of built standalone here. This pass ships Add/Remove only.
* Exit criterion: an admin can create, list, update, and delete Groups.io subgroups directly from the Subgroup Management page, with every write backed by live-verified read-back confirmation; can manually add or remove an individual member's subgroup access from the User Assignment page, with the action recorded as a sticky override and in the audit log; and the Feature Controls page (relocated Phase 1 settings) is reachable from the same "GroupsIO Management" menu — all verified with a screen reader pass, per `CLAUDE.md`'s Accessibility by Design section.

### Phase 4 — Join/Change Sync (Add Path)

* Implement the delta calculation: read a member's `list_subscriptions`, determine applicable mandatory groups (global + level-specific) for their current PMPro level, and compare against Groups.io's actual reported membership.
* Bind to `pmpro_after_all_membership_level_changes` for join/renew/upgrade/downgrade, scheduling an Action Scheduler job (`bits_sync_groupsio_user`) that issues only the add calls needed to close the delta.
* Record an audit log entry for every add attempted, with outcome.
* Exit criterion: joining or upgrading to a level reliably results in the member being added to exactly the correct set of Groups.io subgroups (mandatory + their existing `list_subscriptions` selections), verified end-to-end against the test group, with correct audit entries.

### Phase 5 — Removal on Membership End

* Bind expiration/cancellation to the same delta-and-sync job, respecting the configured grace period before the removal actually executes.
* Bind GDPR/CCPA account erasure to an immediate (no grace period) removal from every `(subgroup id, email)` pair in the member's `list_subscriptions`. Since `removemember` does not batch (PRD section 4.2), this means one `removemember` call per subgroup membership record, looped client-side, not a single call for the whole member.
* Ensure a pending grace-period removal job is cancelled or safely no-ops if the member rejoins/renews before the grace period elapses.
* Exit criterion: expiration and cancellation each correctly remove the member from all applicable subgroups after the grace period, a rejoin within the grace period correctly cancels the pending removal, and account erasure correctly and immediately removes the member from every subgroup they were registered under — all verified end-to-end against the test group.

### Phase 6 — Drift Reconciliation

* Implement the nightly cron job running the same delta calculation across the full active membership base, correcting any mismatch between PMPro's recorded state and actual Groups.io membership. This job also respects any sticky manual-override flag set via Phase 3's User Assignment page, skipping rather than correcting any `(member, subgroup)` pair carrying one.
* Implement the CAN-SPAM global opt-out handling: a member Groups.io reports as globally unsubscribed/bounced/marked spam is flagged and permanently skipped in future adds until manually cleared.
* Implement the mass-action anomaly circuit breaker: track queued add/remove job volume within a rolling window, and once the configured threshold is exceeded, hold the excess jobs in a pending-approval state and send an immediate critical alert, rather than letting them execute automatically.
* Implement suspend as a real User Assignment page action (deferred from Phase 3): a `remove_member()` call plus the sticky-override flag set to `suspended`, since Groups.io's native `banmember` endpoint is broken server-side (confirmed by live trial, 2026-07-25).
* Exit criterion: a manually-induced mismatch (e.g., manually removing a test member directly in Groups.io) is detected and corrected within one nightly run, a simulated global-unsubscribe signal correctly and permanently excludes that member from future mandatory-group re-adds, a simulated burst of jobs exceeding the configured threshold is correctly held pending approval with an admin alert sent, and an admin can suspend a member's subgroup access from the User Assignment page — all verified against the test group.

### Phase 7 — Admin Operability

* Build visibility into Action Scheduler queue health (pending/failed job counts) and the per-member audit view.
* Build the kill switch's actual enforcement (halting all sync processing, including reconciliation, immediately when toggled).
* Build the admin approval UI for jobs held by the mass-action anomaly circuit breaker (Phase 6), letting an admin review and approve or reject held jobs individually or as a batch.
* Build a "Deleted Memberships" page (added to the backlog 2026-08-09, during #61's design): surfaces members whose `(member, subgroup)` rows carry the sticky-override flag set to `removed` - the User Assignment Details page (#61) excludes these from its "currently subscribed" list, so this is the only place an admin can see who's been manually removed and when.
* Exit criterion: an admin can see current sync queue/audit health at a glance, toggling the kill switch verifiably halts all sync activity including in-progress reconciliation, an admin can review and approve a batch of held mass-action jobs, and an admin can view the list of members with a removed-override flag set.

### Phase 8 — Passwordless Profile Access (Magic Link)

* Build the self-service "email me a login link" request form, with rate limiting per email address and per requesting IP, and a generic response regardless of match.
* Build token issuance: cryptographically random token, 15-minute expiration, single-use.
* Build the dedicated plugin email containing the link.
* Build link validation and full authenticated-session login on a valid, unexpired, unused token, landing the member on their WordPress profile page.
* Build audit logging of link issuance, use, and expiry/invalidation events.
* Exit criterion: a member can request a link, receive it, click it within 15 minutes, and land in an authenticated WordPress session exactly once. A second click of the same link, or a click after 15 minutes, is rejected. Rate limiting is verified against repeated requests.

### Phase 9 — Accessibility & Security Hardening

* Full screen reader pass (WCAG 2.1 AA) on every UI surface introduced in prior phases: admin settings screen, level meta box, the GroupsIO Management admin pages, magic link request form, any admin audit views.
* Full verification of the security document's checklist against the shipped code: credential handling, magic link token security, GDPR erasure completeness, rate-limit/auth-failure alerting reliability.
* Validation on the WordPress.com staging site against a mirrored copy of the real PMPro configuration, still targeting the test Groups.io group.
* Exit criterion: the screen reader matrix pass and the security checklist both pass with no unresolved findings, verified on the staging site.

### Phase 10 — v1.0 Release

* Merge `dev` to `main`, tag `v1.0.0`.
* Publish the contribution policy and code of conduct (required, since `app` is a public repository).
* Exit criterion: the release is tagged, release notes are generated, and the plugin is deployed to the live BITS WordPress.com site.

## Post-v1.0 Backlog

Items explicitly scoped as post-release future work, not part of any numbered phase above.

* **Added 2026-08-11**: an in-plugin version of the dev/test data reset-and-repopulate capability currently provided as external tooling (`bin/dev-groupsio-data.sh` - wipes the local member/group index, toggles the dev site between mocked test data and the real dedicated test Groups.io group, and repopulates the local index from whichever is active). An in-plugin equivalent (e.g., an admin-facing control) is explicitly deferred - built as external, non-shipped tooling for now, per direction, since this is a development convenience, not v1 product scope.
