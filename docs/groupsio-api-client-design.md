# Groups.io API Client Design

## BITS Groups.io Membership Sync — Phase 2

### 1. Purpose

This document specifies the design of the Groups.io API client and subgroup-ID cache built in Phase 2 (`app/docs/phase-plan.md`), on top of the contract already confirmed by live trial in Phase 0 (`PRD.md` sections 4.2, 4.3, and 7). It is the design-confirmation step required before a tracking issue is opened and code is written, per `CLAUDE.md`'s Order of Operations.

### 2. Scope

In scope for this pass, matching `phase-plan.md`'s Phase 2 bullet list exactly:

* `GroupsIoApiClient` — wraps `directadd`, `removemember`, `getgroup`, `getsubgroups`, `getmembers` over `wp_remote_request`.
* `SubgroupIdCache` — the slug-to-`group_id` mapping described in PRD section 4.3.
* Unit tests mocking HTTP via WordPress's `pre_http_request` filter, per `testing-standard.md` section 3. No real network calls in the unit suite.

Explicitly not in scope for this pass (later phases):

* Job scheduling, Action Scheduler registration, and retry/backoff orchestration (Phase 4).
* Actually halting sync processing or sending an admin alert email on an auth failure (Phase 7 wires the kill switch and alerting; Phase 2 only needs to surface a distinctly catchable failure so later phases can act on it).
* Integration tests against the live test Groups.io group (present in `testing-standard.md` section 4 as a standing CI responsibility once this client exists, but not written as part of this design pass — see section 8 below for the one manual verification this phase's exit criterion does require).

### 3. Class: `GroupsIoApiClient`

* Namespace `BITS\GroupsIOSync`, file `includes/GroupsIoApiClient.php`, matching the existing flat `includes/` layout and PSR-4 root namespace (no subnamespace, consistent with `Settings`, `AuditLog`, `LevelMandatoryGroups`).
* All methods are `public static`, matching the existing static-utility convention used by `Settings` and `AuditLog` in this codebase, and matching the WP-filter-based HTTP mocking strategy in `testing-standard.md` (no constructor-injected fake transport needed).
* Credentials (`GROUPS_IO_API_KEY`, `GROUPS_IO_PARENT_GROUP`) are read directly from the `wp-config.php` constants at call time, per PRD section 3.1 / Security document section 2 — never cached into a class property that could outlive a single request in an unexpected way, never logged.

Public methods:

* `direct_add( string $group_name, array $emails, array $subgroup_ids ): array` — `POST directadd`. `$subgroup_ids` (one or more numeric IDs) are sent as repeated `subgroupid` fields in a single call, per the confirmed batching behavior (PRD section 4.2). Returns the decoded response body on success.
* `remove_member( int $member_info_id ): array` — `POST removemember`, single `member_info_id` per call. Does not loop or batch — `removemember` doesn't support it (PRD section 4.2), and looping across multiple subgroup memberships is the caller's responsibility (the Phase 4 sync job), not this client's.
* `get_group( string $group_name ): array` — group/subgroup lookup by name string (parent or `parent+subgroup` form), valid at the listing level per PRD section 4.2.
* `get_subgroups( string $parent_group_name ): array` — same name-string form, for listing a parent group's subgroups.
* `get_members( int $group_id ): array` — member-level lookup, which PRD section 4.2 confirms requires the numeric `group_id`, not a name string (name-string form returns `group_not_found` for member-level calls).

Internal request handling (private, shared by all public methods above):

* Builds the request against `https://groups.io/api/v1/<endpoint>`, with `Authorization: Bearer <GROUPS_IO_API_KEY>`.
* On a WordPress-level transport failure (`wp_remote_request` returning a `WP_Error` — DNS failure, connection timeout, etc.) or an HTTP 5xx: throws `GroupsIoTransportException`. This class does not retry internally — per PRD section 7, that's Action Scheduler's job (exponential backoff), configured by the Phase 4 caller, not this client.
* On HTTP 429: parses the `Retry-After` header and throws `GroupsIoRateLimitException`, carrying the parsed seconds. Per PRD section 7, actually rescheduling with jitter is the caller's job — this client only surfaces the parsed value.
* On HTTP 400 with the confirmed `{"object":"error","type":"...","extra":"..."}` body (PRD section 7): throws `GroupsIoApiException`, carrying the raw `type` and `extra` values verbatim (not mapped to an enum — Groups.io's documented `type` values are treated as a confirmed but possibly-incomplete list, so an unrecognized future `type` string still surfaces correctly rather than being coerced into a wrong bucket or silently dropped).
* On any other unexpected non-2xx status: throws `GroupsIoApiException` with `type` set to an internal marker (`unexpected_status`) and `extra` containing the raw status code and body, so it's still catchable via the same exception type rather than being a second, different failure shape callers must separately handle.
* On success (2xx): returns the JSON-decoded response body as an array.
* When `WP_DEBUG` is true, the raw request (method, endpoint, body) and response (status, body) are written to the standard WordPress debug log, with the `Authorization` header value redacted before logging — required by Security document section 2 (never log the credential) and section 4 (explicit redaction requirement for this exact logging path), and by PRD section 6 (this debug logging is itself a PRD requirement, not optional instrumentation).

### 4. Exception Hierarchy

A small, flat hierarchy — not a class per documented Groups.io error `type` value, since that would be speculative scaffolding for error types that may never need distinct handling (`CLAUDE.md`'s Scope Discipline: no feature built beyond what confirmed v1 scope requires).

* `GroupsIoApiException extends \RuntimeException` — base class. Exposes `get_error_type(): string` and `get_extra(): string` (the raw `type`/`extra` from the confirmed error body, or the internal `unexpected_status` marker case). Callers needing to distinguish `unauthorized_error`/`inadequate_permissions` (hard-stop) from `group_not_found` (skip-and-continue plus cache invalidation) from anything else do so by branching on `get_error_type()`, not via further subclassing — that branching logic belongs to the Phase 4/6/7 callers who know what to *do* about each case, not to the client.
* `GroupsIoRateLimitException extends GroupsIoApiException` — adds `get_retry_after_seconds(): int`. A distinct subclass (not just another `get_error_type()` value) because 429 isn't part of the confirmed `{"object":"error","type":...}` body shape at all — it's a different HTTP status with a different response shape (PRD section 7 distinguishes it explicitly from the 400 error-type family), so it doesn't have a `type` string to carry in the first place.
* `GroupsIoTransportException extends \RuntimeException` — network-level failures and 5xx. Deliberately **not** a subclass of `GroupsIoApiException`, since it has no Groups.io-originated error type to expose at all (it's WordPress/network-level, not a Groups.io API response) — conflating it into the same hierarchy would mean `GroupsIoApiException` sometimes has a real `type` and sometimes doesn't, which is worse than two clearly-separated exception classes.

### 5. Class: `SubgroupIdCache`

* Namespace `BITS\GroupsIOSync`, file `includes/SubgroupIdCache.php`, same static-utility convention.
* Storage: a single WordPress option (`bits_groupsio_subgroup_cache`), holding a flat `slug => group_id` array. Not a transient — PRD section 4.3 describes explicit invalidation triggers (a `group_not_found` error on a cached ID, and periodic refresh during nightly reconciliation), not time-based expiry, so a TTL-based transient would be the wrong tool and could invalidate correct entries for no reason, or fail to invalidate stale ones promptly.
* `get_group_id( string $slug ): int` — returns the cached ID if present; on a cache miss, resolves it via `GroupsIoApiClient::get_subgroups()` against the configured parent group (`GROUPS_IO_PARENT_GROUP`), caches the result, and returns it. Throws `GroupsIoApiException` (propagated from the client, e.g. `group_not_found` if the slug genuinely doesn't exist) rather than swallowing the error — a cache class shouldn't decide what a lookup failure means, only cache the successful result.
* `invalidate( string $slug ): void` — removes a single slug from the cached mapping. Called by the Phase 4 caller when it catches a `group_not_found` error on a previously-cached ID (PRD section 4.3's first refresh trigger). This class doesn't catch that exception itself — it exposes the invalidation primitive; deciding *when* to call it belongs to the code that made the failing call and knows the ID was stale, not to the cache.
* `refresh_all(): void` — re-resolves every currently-cached slug against `GroupsIoApiClient::get_subgroups()` and overwrites the stored mapping. Called by the Phase 6 nightly reconciliation job (PRD section 4.3's second refresh trigger) — Phase 2 builds the capability now since it's a natural piece of this class's responsibility, but nothing in Phase 2 itself invokes it yet, since the reconciliation job doesn't exist until Phase 6.

### 6. Testing Approach

Per `testing-standard.md` section 3:

* All HTTP is mocked via WordPress's `pre_http_request` filter — no real network calls, no injected fake transport object.
* Coverage required for `GroupsIoApiClient`: success path for each of the five methods; the confirmed 400 error-type dispatch (`unauthorized_error`, `inadequate_permissions`, `bad_request`, `group_not_found`, and one unrecognized `type` string to confirm the exception still carries it correctly rather than failing to construct); 429 with a `Retry-After` header (confirm the parsed seconds value); a `WP_Error`-simulated transport failure; a 5xx response; the `WP_DEBUG` redaction behavior (assert the logged payload never contains the raw API key, only when `WP_DEBUG` is toggled on for that test).
* Coverage required for `SubgroupIdCache`: cache hit (no client call made); cache miss (client called, result cached, second call is then a hit); `invalidate()` removing exactly the targeted slug and no others; `refresh_all()` re-resolving every cached slug.
* These are unit tests only, per this design's scope (section 2) — the integration-test responsibility already described in `testing-standard.md` section 4 (real calls against the test Groups.io group) is picked up as this client's consumers are built in later phases, not written against a client with no callers yet.

### 7. Lookup Endpoint HTTP Method — Confirmed

PRD section 4.2 confirms `directadd` and `removemember` are `POST`, by live trial against the test group in Phase 0. It did not state the HTTP method for `getgroup`, `getsubgroups`, or `getmembers`, since those lookups weren't exercised live in that phase — only the name-string-vs-numeric-ID behavior described in PRD section 4.2's third bullet was confirmed for them.

**All three confirmed by live trial against the test group** (during this design pass, using the real test group `perception-is-all`):

* `getgroup?group_name=perception-is-all` — `GET` returned `HTTP 200` with the full group object.
* `getsubgroups?group_name=perception-is-all` — `GET` returned `HTTP 200` with a list of 2 subgroups, each with its numeric `id` (e.g. `152360`, `152357`).
* `getmembers?group_id=<numeric subgroup id from getsubgroups>` — `GET` returned `HTTP 200` with a list of `member_info` records for that subgroup, confirming the numeric-`group_id` requirement from PRD section 4.2 as well as the HTTP method.

This independently verifies `getmembers` as behaviorally distinct-but-still-`GET`, rather than relying on the by-analogy inference originally proposed — the earlier attempts against this same live group returned `group_not_found` only because the trial calls used an incorrectly-formatted `group_name` value (an email-style list address, not the bare-slug form), not because of anything wrong with the method or contract; once corrected, all three endpoints resolved cleanly.

### 8. Exit Criterion (per `phase-plan.md`)

"The client is unit-tested for all documented response/error paths, and has been manually exercised at least once against the existing test Groups.io group(s), successfully adding to and removing from a subgroup." The manual exercise covers `direct_add()` and `remove_member()` specifically (matching what Phase 0 already demonstrated); the lookup methods' HTTP method is now confirmed per section 7 above, ahead of this exit criterion being reached.
