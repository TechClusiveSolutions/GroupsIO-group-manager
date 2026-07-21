---
applyTo: "includes/GroupsIoApiClient.php,includes/GroupsIoApiException.php,includes/GroupsIoRateLimitException.php,includes/GroupsIoTransportException.php,includes/SubgroupIdCache.php,includes/AuditLog.php,includes/Settings.php"
---

# Security-Sensitive Code Instructions

Per `CLAUDE.md`'s Security Gate: any change touching stored content,
credentials, authentication, or the Groups.io API is checked against
`docs/security.md` before being considered complete. This file's `applyTo`
list covers the classes that currently touch those areas — extend the list
in this file's frontmatter as new security-sensitive files are added (the
magic link mechanism in a later phase, for example), rather than relying on
this guidance applying only by coincidence of file location.

## Credentials

* `GROUPS_IO_API_KEY`, `GROUPS_IO_PARENT_GROUP`, and
  `GROUPS_IO_NOTIFICATION_EMAIL` are `wp-config.php` constants, read via
  `defined()`/direct constant access at the point of use — never stored in
  an option, never cached into a class property that could outlive a single
  request in an unexpected way, never exposed through any admin UI field.
* No credential, real or placeholder-looking, is ever committed — including
  in code comments, test fixtures, or documentation examples. Use obviously
  fake values (e.g., `fake-test-key-not-real`, matching this project's
  existing convention in `.wp-env.json`).
* The `Authorization` header (or any other place a credential value could
  appear) must never be written to a log, debug output, or exception
  message. `GroupsIoApiClient`'s debug-logging path (`WP_DEBUG`-gated, per
  PRD section 6) satisfies this by never including request headers in what
  it logs at all — don't "fix" this by adding headers back in with a
  redaction step; omission is simpler and strictly safer than
  redact-after-the-fact.

## Groups.io API Error Handling

* Dispatch on the confirmed error `type` field (PRD section 7), not on HTTP
  status alone, for anything other than 429/5xx — Groups.io returns `400`
  for essentially every application-level error, with the real distinction
  carried in the JSON body's `type`.
* `unauthorized_error`/`inadequate_permissions` is a hard-stop signal, not
  an ordinary error to retry or skip past — a caller catching
  `GroupsIoApiException` for this case should halt processing and alert,
  not silently continue.
* All Groups.io-touching operations must be idempotent-tolerant — retried
  or reconciliation-triggered calls need to treat "already a member"/
  "already removed" responses as non-errors, since retries and nightly
  reconciliation can both act on the same member (PRD section 7).

## Stored/Audit Data

* The audit table (`bits_groupsio_audit`) stores personal data (emails tied
  to identifiable members). Any code reading it needs a capability check
  equivalent to what `Settings::render_page()` already does
  (`current_user_can( 'manage_options' )`) before displaying it — this data
  isn't public-admin-readable by default assumption.
* Audit log entries are retained for their full configured retention period
  even after a member's account is erased under GDPR/CCPA (PRD section 8,
  `docs/security.md` section 3) — this is a deliberate, documented
  exception to erasure, not a bug. Don't "fix" audit retention code to
  delete on account erasure without checking this section first.

## Before Calling Security-Sensitive Work Done

* Check the change against the relevant section(s) of `docs/security.md`
  explicitly — don't rely on general security instincts alone for this
  project's specific, documented threat model (credential compromise,
  mass-action anomalies, drift-reconciliation false positives, CI secret
  exposure — see `docs/security.md` section 4).
* If a new operational security threshold is involved (rate limits,
  mass-action anomaly threshold, credential rotation cadence), its *exact
  numeric value* does not belong in this repo's public documentation — see
  `docs/implementation-standard.md` for which values are deliberately
  redacted and why, and the private security addendum (outside this
  repository) for the real values.
