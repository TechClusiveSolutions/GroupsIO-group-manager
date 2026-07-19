# Product Requirements Document

## BITS Groups.io Membership Sync

### 1. Purpose

This project eliminates manual administration of BITS (Blind Information Technology Solutions) email lists on Groups.io. It automatically adds members to the Groups.io mailing lists they've selected, and automatically removes them from all BITS mailing lists when their Paid Memberships Pro (PMPro) membership ends. It also provides a passwordless "magic link" mechanism so members can reach their WordPress profile without needing to recall a password.

This document supersedes the earlier working draft (`PmPro-GroupsIO-PRD.md`, retained in the parent working directory for historical reference only).

### 2. Scope

#### 2.1 In scope for v1

* Reading a member's existing mailing list selections (already captured and maintained by pre-existing PMPro forms outside this project) and syncing Groups.io to match.
* Automatically removing a member from all BITS Groups.io mailing lists when their membership ends (expiration, cancellation, or account deletion).
* A grace period before removal on expiration/cancellation, to avoid removing members during transient failed-payment retries.
* Immediate (non-grace-period) removal on GDPR/CCPA right-to-be-forgotten account erasure.
* Honoring Groups.io-side global unsubscribe/bounce/spam signals (CAN-SPAM compliance) by permanently excluding an affected member from future re-adds.
* A nightly reconciliation job that detects and corrects drift between PMPro's recorded state and actual Groups.io membership.
* A resilient Groups.io API client with rate-limit (429) and transient-failure handling, and a hard-stop on authentication failure (403) with admin alerting.
* An audit log of every add/remove action attempted, with outcome.
* An admin settings screen for operational configuration (see section 3.3).
* A passwordless "magic link" mechanism granting full WordPress profile access, per section 5.
* Basic admin operability: visibility into pending/failed sync jobs, and a kill switch to halt all sync activity.

#### 2.2 Explicitly out of scope for v1 (backlog)

The following were present in the working draft PRD but are not part of this project unless separately re-scoped in the future:

* Building any UI for a member to select or change their mailing list preferences (join-time or update). This lives entirely in pre-existing PMPro forms outside this project.
* Delivery-mode tiering (e.g., Groups.io "Individual Email" vs. "Daily Digest" mapped to membership tier).
* Corporate/umbrella account cascading (parent/child sponsored membership handling).
* Moderation pre-approval flagging for moderated Groups.io subgroups.
* A dedicated health-dashboard widget on the WP dashboard home screen.
* Vacation mode / mute (`delivery_type: nomail` toggling).
* Real-time AJAX validation of email addresses against Groups.io during profile editing.
* A webhook receiver for real-time Groups.io-side unsubscribe events (v1 relies on the nightly reconciliation job only).

### 3. Configuration Model

#### 3.1 Constants (not admin-editable, never committed)

Defined in the site's `wp-config.php`, following the same convention as core WordPress database credentials:

* `GROUPS_IO_API_KEY` — the API key for a Groups.io account with Owner/Manager permissions.
* `GROUPS_IO_PARENT_GROUP` — the slug for the main BITS group (e.g., `bits`).
* `GROUPS_IO_NOTIFICATION_EMAIL` — the admin address for critical error alerts.

These values are never stored in the database, never exposed through an admin UI, and never committed to the repository. Only clearly fake placeholder values appear in documentation and examples.

#### 3.2 Admin-Configurable Settings (database-backed, editable via this plugin's admin UI)

* Global mandatory groups — subgroups every active member must belong to, regardless of tier.
* Level-specific mandatory groups — subgroups tied to a particular PMPro membership level, configured on that level's edit screen.
* Grace period — the delay, in days, between a membership expiring/being cancelled and the removal job actually executing.
* Log retention policy — how many days of audit log entries are kept before automated cleanup.
* Kill switch — a toggle that halts all sync processing (new adds, removes, and reconciliation) immediately.
* Mass-action anomaly threshold — the job count and time window beyond which queued add/remove jobs are automatically held pending admin approval rather than executing, with an immediate critical alert sent to `GROUPS_IO_NOTIFICATION_EMAIL` (see the Security document's Threat Model for the rationale).
* Magic link request rate limits — maximum requests per email address and per requesting IP within a rolling window (defaults set in the Implementation Standard document).
* Reconciliation dry-run mode — a toggle causing the nightly reconciliation job to compute and log its delta without executing any add/remove calls (see the Implementation Standard document for the recommended rollout use of this toggle).

Whether a `wp-config.php` constant may also override any of these database-backed settings, as an advanced operational escape hatch, is an implementation-level decision to be made in the Implementation Standard document, not a v1 requirement.

#### 3.3 Existing Data Structure (read-only input to this app)

Member list preferences already exist in the `list_subscriptions` user meta key, maintained entirely outside this project by pre-existing PMPro forms. This app treats it as a read-only source of truth for "desired state." Format:

```php
[
    ['id' => 'subgroup_slug_1', 'email' => 'user@example.com'],
    ['id' => 'subgroup_slug_2', 'email' => 'alias@example.com'],
]
```

Each entry pairs a Groups.io subgroup slug with the specific email address that member wants used for that subgroup. A single member may be registered under different email addresses for different subgroups. Consequently:

* Sync, removal, and reconciliation logic must operate on `(subgroup id, email)` pairs, not on a single "the member's email."
* Removing a member on membership end means removing every `(subgroup id, email)` pair present in their `list_subscriptions` at the time removal executes — not assuming a single email applies across all their subgroups.

### 4. Workflow and Event Triggers

#### 4.1 Trigger events

* PMPro level changes (join, renew, expire, cancel, upgrade, downgrade) via `pmpro_after_all_membership_level_changes` (preferred over `pmpro_after_change_membership_level`, per PMPro's own documentation, since it fires exactly once per page load even when multiple level changes occur in one request). Expiration and cancellation schedule a removal job honoring the configured grace period; joining or upgrading schedules an add job for any newly-applicable mandatory/selected subgroups.
* GDPR/CCPA account erasure via WordPress's native personal-data-erasure hooks — schedules an immediate (no grace period) full removal from every subgroup the member is registered under.

#### 4.2 Processing model

* Each trigger schedules a background job via Action Scheduler (`bits_sync_groupsio_user`), rather than executing inline, so member-facing requests (checkout, admin actions) are never blocked on a Groups.io API round-trip.
* Job logic: read the member's current `list_subscriptions`, determine mandatory groups (global + level-specific) applicable to their current PMPro level, compute the delta against Groups.io's actual reported membership, and issue only the add/remove operations needed to close that delta.
* Nightly reconciliation runs the same delta computation across the full active membership base as a drift-correction backstop, independent of whether a triggering event fired.
* **Open implementation question**: public Groups.io API documentation does not clearly expose independent per-subgroup add/remove endpoints. Groups.io's "Direct Add" feature appears to operate at the parent-group level, with target subgroups specified as part of that same call, rather than through separate `subgroup/members/add` / `subgroup/members/remove` calls as earlier drafts of this PRD assumed. The exact request/response contract (whether add and remove are distinct calls, what parameters they take, whether they can be scoped to only certain subgroups for an already-parent-group member) must be verified directly — against the Groups.io API reference while authenticated, or by direct trial against the test group — as a required first step of Phase 2 (Groups.io API Client), not assumed from this document.

### 5. Passwordless Profile Access (Magic Link)

* Purpose: let a member reach their WordPress profile without needing to recall a password.
* Request flow: a self-service "email me a login link" form, accepting only an email address. The response is generic regardless of whether the address matches an account (e.g., "if that address exists, a link has been sent"), to prevent email enumeration.
* Delivery: a dedicated email sent by this plugin, containing a single link.
* Token properties: cryptographically random (not guessable/sequential), valid for 15 minutes from issuance, single-use (invalidated immediately upon first use, regardless of expiry).
* Access granted: a full, genuinely authenticated WordPress session for that member — equivalent to a normal login, landing on their WordPress profile page.
* Abuse mitigation: rate limiting per email address and per requesting IP on the request form.
* Auditing: link issuance, use, and expiry/invalidation are logged as their own audit event type, alongside (but distinct from) the Groups.io sync audit trail.
* Full security parameters (token generation/storage mechanism, interaction with any existing 2FA, session scope) are specified in the Security document, not this PRD.

### 6. Auditing and Logging

* A custom database table (`bits_groupsio_audit`) records every sync action attempted: timestamp, user ID, target email, subgroup ID, action (add/remove), outcome (success/fail), and API response detail.
* Automated cleanup removes entries older than the configured log retention policy.
* Magic link events are logged separately per section 5.
* When `WP_DEBUG` is active, raw request/response payloads to Groups.io are additionally written to the standard WordPress debug log.

### 7. Error Handling and Resilience

* HTTP 429 (rate limited): the `Retry-After` header is parsed, and the job is rescheduled to run after that timeout, with jitter added to avoid synchronized retry storms when many jobs are rate-limited simultaneously.
* Network timeouts / 5xx errors: Action Scheduler retries automatically with exponential backoff (e.g., 2 minutes, 10 minutes, 1 hour) up to a defined attempt limit.
* HTTP 403 (forbidden): all sync processing halts immediately; a critical alert is sent to `GROUPS_IO_NOTIFICATION_EMAIL`; the audit log records a `CRITICAL_AUTH_FAILURE` entry.
* HTTP 404 (subgroup not found): the specific invalid subgroup is skipped with a `GROUP_NOT_FOUND` audit entry; processing continues for the member's other valid subgroups.
* All job processing is idempotent — retried or reconciliation-triggered operations must tolerate "already a member" / "already removed" responses as non-errors, since retries and nightly reconciliation can both act on the same member.
* **Open implementation question**: preliminary research suggests the Groups.io API may return errors as HTTP 400 with a distinguishing error-type field (e.g., `unauthorized_error`, `inadequate_permissions`, `bad_request`, `group_not_found`) rather than using distinct 403/404 HTTP status codes as this section currently assumes. The exact error response shape must be confirmed against real API responses (via the test group) during Phase 2, and this section's 403/404 handling revised to match whatever the API actually returns before Phase 2's exit criterion is considered met.

### 8. Legal, Compliance, and Privacy

* CAN-SPAM global opt-out: if Groups.io reports a member as globally unsubscribed, bounced, or marked as spam, this is recorded (e.g., a `groupsio_global_opt_out` flag), and the sync engine permanently skips that member in future add operations, including mandatory-group adds, until the condition is manually cleared.
* GDPR/CCPA right to be forgotten: WordPress's native personal-data-erasure flow triggers an immediate removal from every subgroup the member is registered under, per section 4.1.
* The audit log's retention (per the configured policy) serves as proof-of-transaction for compliance purposes. Audit log entries are **not** erased when a member's account is erased under GDPR/CCPA — they are retained for their full configured retention period regardless of account erasure, on the basis that they constitute a transaction/compliance record rather than the member's personal profile data. This retained-audit-record exemption, and its legal basis, must be documented explicitly in the Security document and, if BITS maintains a public privacy policy, reflected there as well.

### 9. Open Items Requiring Verification Before Phase 2 (Groups.io API Client)

* ~~Confirm the actual Groups.io API authentication mechanism~~ — resolved: this app authenticates using an API key via `Authorization: Bearer` header (`GROUPS_IO_API_KEY`, per section 3.1), not session/cookie-based login. Confirmed against Groups.io's public API reference and help documentation.
* ~~Confirm the exact request/response contracts for the add-member, remove-member, and list-members endpoints~~ — partially resolved, still requires live verification: Groups.io's model appears to be a parent-group-level "Direct Add" operation with subgroups specified as a parameter, rather than independent per-subgroup add/remove endpoints as originally assumed (see section 4.2). The precise contract — parameters, whether add/remove are distinct calls, exact error response shape (possibly HTTP 400 with an error-type field rather than distinct 403/404 statuses, see section 7) — must be confirmed by direct trial against the test Groups.io group as the first concrete task of Phase 2, since public documentation does not fully expose it.
* Confirm whether Groups.io supports true batch add/remove calls, or whether calls are inherently per-member/per-subgroup.
* Confirm WordPress.com Business Plan hosting supports everything this design assumes (Action Scheduler, custom database tables via `dbDelta`, wp-config constants, staging site feature).
* ~~Confirm PMPro's membership-level-change hook~~ — resolved: bind to `pmpro_after_all_membership_level_changes` rather than `pmpro_after_change_membership_level`, per PMPro's own documentation (see section 4.1).
