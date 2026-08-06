# Security Document

## BITS Groups.io Membership Sync

### 1. Purpose

This document is the security gate referenced by `CLAUDE.md`. Any change touching stored content, credentials, authentication, or the Groups.io API is checked against this document before being considered complete. It covers the threat model, credential handling, data-at-rest policy, the magic link authentication mechanism, and third-party data-disclosure posture.

### 2. Credential Handling

* `GROUPS_IO_API_KEY`, `GROUPS_IO_PARENT_GROUP`, and `GROUPS_IO_NOTIFICATION_EMAIL` are defined as `wp-config.php` constants, following the same convention as core WordPress database credentials (per PRD section 3.1).
* These constants are never stored in the WordPress database, never exposed through any admin UI field, and never committed to the repository under any circumstance, including as placeholder-looking real values.
* Only clearly fake placeholder values (e.g., `sk_test_FAKE_EXAMPLE_ONLY`) appear in documentation, examples, or code comments.
* The Groups.io API key belongs to an account with Owner/Manager permissions on the BITS group. Because this is a single, powerful, org-wide credential, it must be treated as a production secret with the same handling rigor as a database root credential: known only to those administering the WordPress.com site directly, rotated if any suspected exposure occurs, and never shared over email or chat.
* CI integration tests (see section 6) require a separate, lower-privilege credential scoped to the dedicated test Groups.io group(s), never the production BITS group credential.

### 3. Data at Rest

* The `bits_groupsio_audit` table stores: timestamp, user ID, target email, subgroup ID, action, outcome, and API response detail. This is personal data (email addresses tied to identifiable members) and is treated accordingly for access-control purposes — only WordPress administrators with appropriate capability can view the admin audit UI.
* Per PRD section 8, audit log entries are retained for their full configured retention period even after a member's account is erased under GDPR/CCPA, on the basis that they constitute a transaction/compliance record distinct from the member's personal profile. This is a deliberate, documented exception to full erasure, disclosed here as the authoritative record of that decision. If BITS maintains a public privacy policy, this retention behavior must be reflected there in plain language (e.g., "records of group membership changes are retained for up to N days for compliance purposes even after account deletion").
* The `list_subscriptions` user meta field (the existing PMPro-maintained preference data this app reads) is not owned or written by this app, but this app's audit trail necessarily duplicates fragments of it (subgroup IDs, emails) into `bits_groupsio_audit`. The retention policy above applies equally to this duplicated data.
* Magic link tokens (section 5) are never stored in a way that lets the raw, usable token be recovered from the database after issuance — only a irreversible hash/digest of the token is stored, so a database compromise alone (without the original emailed link) cannot be used to forge or replay valid sessions. Token records store: hashed token, associated user ID, issuance timestamp, expiry timestamp, used-at timestamp (null until used), and requesting IP.

### 4. Threat Model

* **Compromised Groups.io API key**: an attacker with the production key could add or remove any member from any BITS group, or read the full member list. Mitigation: the key lives only in `wp-config.php` on the production host, is never logged (including in `WP_DEBUG` payload logging — see section 4 sub-bullet below), and an `unauthorized_error` or `inadequate_permissions` response from Groups.io (the confirmed error-type values for an auth/permission failure — see PRD section 7) triggers an immediate hard-stop of all sync processing plus an admin alert, on the theory that a sudden auth failure may indicate the key was rotated or revoked out-of-band (possibly due to compromise) rather than simply a misconfiguration.
* **`WP_DEBUG` payload logging** (PRD section 6): raw request/response payloads written to the debug log must have the `Authorization` header redacted before logging, since `wp-content/debug.log` is not held to the same access-control standard as the audit database table and could be more broadly readable on some hosting configurations.
* **Magic link interception or forwarding**: an attacker who obtains a member's magic-link email (e.g., a compromised or shared inbox, a forwarded email) gains full authenticated access to that member's WordPress profile for up to 15 minutes, once. Mitigation: short expiry, single-use invalidation, and audit logging of issuance/use so an admin can identify and investigate a suspicious login if reported.
* **Magic link request-form abuse (enumeration or flooding)**: an attacker submitting many email addresses to the request form could try to (a) determine which addresses have accounts by observing response differences, or (b) flood a member's inbox with unwanted login-link emails. Mitigation: the response is identical regardless of match (PRD section 5), and rate limiting applies per submitted email address and per requesting IP, with the same limits applying regardless of whether the address matched an account (so response timing doesn't leak a match either).
* **CI secret exposure**: the test-group Groups.io credential lives as a GitHub Actions secret, since integration tests run automatically on every PR (a decision made explicitly, accepting this tradeoff — see section 6). Mitigation: GitHub environment protection rules restrict this secret to workflow runs triggered from within the `TechClusiveSolutions` org (not from external fork pull requests), and the test-group credential is scoped to test group(s) only, never the production BITS credential, so its exposure cannot affect real members.
* **Drift-reconciliation false positives**: if the nightly reconciliation job has a defect that misreads "desired state," it could mass-remove or mass-add real members across the entire BITS membership in a single run. Mitigation: the kill switch (PRD section 3.2) allows immediate halt of all sync processing including reconciliation; reconciliation runs should be considered for a "dry run" / report-only mode as an operational safeguard, decided in the Implementation Standard document.
* **Mass-action anomaly**: any burst of add/remove jobs queued at once or within a defined short time window — whether from a reconciliation defect, a PMPro bulk operation, a bug, or a compromised credential being used to script mass changes — is inherently suspicious at BITS's scale, since normal usage should only ever queue jobs for individual members reacting to individual events. Mitigation: the sync engine tracks the volume of add/remove jobs queued within a rolling time window; if that volume exceeds a configured threshold, an immediate critical alert is sent to `GROUPS_IO_NOTIFICATION_EMAIL`, and the jobs exceeding the threshold are held in a pending-approval state rather than executing automatically — they only proceed once an admin explicitly approves them (individually or as a batch) via the admin UI. This applies in addition to, not instead of, the kill switch: the kill switch is a manual full-stop, while this is an automatic circuit breaker for a specific anomalous pattern. The exact threshold (job count and time window) is an operational setting, decided alongside the other admin-configurable settings in PRD section 3.2.

### 5. Magic Link Authentication — Detailed Security Parameters

* Token generation: a cryptographically secure random value (e.g., via PHP's `random_bytes`), of sufficient length to be infeasible to guess or brute-force within its 15-minute validity window even accounting for the rate limiting in section 4.
* Token storage: only a hash of the token is persisted (see section 3); the raw token exists only in the emailed link itself.
* Token validation: on click, the submitted token is hashed and looked up; a match that is unexpired and unused grants the session and immediately marks the token used. Any other outcome (no match, expired, already used) is rejected with a generic error, not a specific reason, to avoid giving an attacker probing information about why a given token failed.
* Session behavior: clicking a valid link produces a full, genuinely authenticated WordPress session for that member (PRD section 5) — equivalent to a normal password login. If the BITS site has any 2FA requirement configured for some members, whether the magic link satisfies or bypasses that requirement is an open question to resolve before Phase 8 is built, since a passwordless link that bypasses a member's own 2FA would weaken, not merely replace, their account's protection.
* Rate limiting specifics (thresholds, lockout duration) are an implementation-level decision for the Implementation Standard document, but the requirement that limits apply per-email and per-IP, with a generic response, is a hard requirement fixed here.

### 6. Third-Party API and CI Secret Posture

* No project bundles or hardcodes third-party API keys, OAuth application credentials, or test account tokens, per `CLAUDE.md`'s Security Gate.
* Integration tests run automatically on every pull request against the dedicated test Groups.io group(s) (a decision made explicitly in planning, accepting the tradeoffs below).
* The test-group Groups.io API key is stored as a GitHub Actions secret, scoped via environment protection rules so it is not exposed to workflow runs triggered by external fork pull requests.
* The test-group credential is rotated on a defined cadence (specific cadence to be set in the Implementation Standard document) and immediately if any suspected exposure occurs.
* No integration test ever targets the production BITS Groups.io group or a real member's data.

### 7. Accessibility/Security Overlap

* The magic link request form and any admin-facing security-relevant UI (settings screen, audit views) are subject to the same WCAG 2.1 AA requirement as the rest of the plugin's UI (per `CLAUDE.md`) — a security feature that isn't usable by a screen reader user is a defect, not an acceptable tradeoff, given BITS's mission.
