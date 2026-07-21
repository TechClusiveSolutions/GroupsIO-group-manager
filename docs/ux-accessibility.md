# UX / Accessibility Document

## BITS Groups.io Membership Sync

### 1. Purpose

This document specifies the accessibility standard this project must meet, which UI surfaces are in scope, and how verification happens, per `CLAUDE.md`'s Accessibility by Design section.

### 2. Standard

* Target: WCAG 2.1, Level AA, for every UI surface this plugin introduces.
* No feature is considered complete until it has been verified with a screen reader, per `CLAUDE.md` — this applies even to admin-only screens, since BITS's own staff and volunteers may include blind or low-vision users.

### 3. UI Surfaces in Scope

* The admin settings screen for operational configuration (mandatory groups, grace period, log retention, kill switch, mass-action anomaly threshold, magic link rate limits, reconciliation dry-run toggle — PRD section 3.2).
* The level-specific mandatory groups meta box on the PMPro Edit Membership Level screen.
* The magic link self-service request form.
* The mass-action anomaly pending-approval admin UI (Phase 6).
* The per-member audit view and any queue-health dashboard surface (Phase 6).
* Explicitly out of scope: any UI for selecting or changing mailing list preferences, since that lives entirely in pre-existing PMPro forms outside this project (PRD section 2.2).

### 4. Verification Process

* Default local verification: a screen reader pass by the primary contributor, using their own day-to-day screen reader as the default local verification tool, on any change touching a UI surface listed in section 3. This local pass is what's required before merging such a change to `dev` — per `docs/definition-of-done.md` section 3, the fuller matrix pass below is reserved for the Phase 8 gate, not every individual feature issue.
* Fuller matrix pass: mandatorily as part of Phase 8's exit criterion (not before every individual `dev` merge), a broader screen reader matrix (e.g., NVDA, JAWS, VoiceOver) is run. Resourcing for who runs the non-default screen readers in that matrix is to be determined closer to Phase 8.
* Verification happens on the WordPress.com staging site for the Phase 8 pass, so it reflects the real PMPro-configured environment, not just the local `wp-env` instance.

### 5. Specific Accessibility Requirements Worth Calling Out

* The magic link request form must clearly announce, to a screen reader, both the generic confirmation message after submission and any rate-limit-triggered state — without the rate-limit state ever revealing more specific information than the generic message would (the accessible experience must not become a side channel that leaks enumeration information, per the Security document's mitigation).
* Admin alert emails (critical auth failure, mass-action anomaly) are plain, well-structured text/HTML email — not requiring any visual-only cue (e.g., color alone) to convey severity.
* The pending-approval admin UI for mass-action jobs must make the review/approve/reject action for each held job unambiguous via accessible labels, not relying on visual grouping or table layout alone to convey which job a given action applies to.
