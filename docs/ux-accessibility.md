# UX / Accessibility Document

## BITS Groups.io Membership Sync

### 1. Purpose

This document specifies the accessibility standard this project must meet, which UI surfaces are in scope, and how verification happens, per `CLAUDE.md`'s Accessibility by Design section.

### 2. Standard

* Target: WCAG 2.1, Level AA, for every UI surface this plugin introduces.
* No feature is considered complete until it has been verified with a screen reader, per `CLAUDE.md` — this applies even to admin-only screens, since BITS's own staff and volunteers may include blind or low-vision users.

### 3. UI Surfaces in Scope

* The GroupsIO Management admin area's three pages (shipped, Phase 3):
  * **Feature Controls** — operational configuration (mandatory groups, grace period, log retention, kill switch, mass-action anomaly threshold, magic link rate limits, reconciliation dry-run toggle — PRD section 3.2, most still pending later phases).
  * **Subgroup Management** — the List, Create, and Details views, including the inline delete confirmation.
  * **User Assignment** — the List, Details, and Add Groups views, including the parent-group removal confirmation step and the owner-removal-blocked notice.
  * The "Sync" manual-processing control present on every view of all three pages above.
* The level-specific mandatory groups meta box on the PMPro Edit Membership Level screen.
* The magic link self-service request form.
* The mass-action anomaly pending-approval admin UI (Phase 7).
* The per-member audit view and any queue-health dashboard surface (Phase 7).
* Explicitly out of scope: any UI for selecting or changing mailing list preferences, since that lives entirely in pre-existing PMPro forms outside this project (PRD section 2.2).

### 4. Verification Process

* Default local verification: a screen reader pass by the primary contributor, using their own day-to-day screen reader as the default local verification tool, on any change touching a UI surface listed in section 3. This local pass is what's required before merging such a change to `dev` — per `docs/definition-of-done.md` section 3, the fuller matrix pass below is reserved for the Phase 9 gate, not every individual feature issue.
* Fuller matrix pass: mandatorily as part of Phase 9's exit criterion (not before every individual `dev` merge), a broader screen reader matrix (e.g., NVDA, JAWS, VoiceOver) is run. Resourcing for who runs the non-default screen readers in that matrix is to be determined closer to Phase 9.
* Verification happens on the WordPress.com staging site for the Phase 9 pass, so it reflects the real PMPro-configured environment, not just the local `wp-env` instance.

### 5. Specific Accessibility Requirements Worth Calling Out

* The magic link request form must clearly announce, to a screen reader, both the generic confirmation message after submission and any rate-limit-triggered state — without the rate-limit state ever revealing more specific information than the generic message would (the accessible experience must not become a side channel that leaks enumeration information, per the Security document's mitigation).
* Admin alert emails (critical auth failure, mass-action anomaly) are plain, well-structured text/HTML email — not requiring any visual-only cue (e.g., color alone) to convey severity.
* The pending-approval admin UI for mass-action jobs must make the review/approve/reject action for each held job unambiguous via accessible labels, not relying on visual grouping or table layout alone to convey which job a given action applies to.

### 6. Keyboard Shortcuts (Access Keys)

* Every actionable submit button and navigation link on the GroupsIO Management admin area's three pages (Feature Controls, Subgroup Management, User Assignment) carries an HTML accesskey attribute, activated as Alt+*letter* in Chrome (the primary contributor's browser) and exposed to screen readers as a standard accesskey announcement (JAWS, the primary contributor's screen reader, announces the assigned key when the control receives focus).
* Per-row, per-item dynamic links (e.g., each subgroup's row link in the Subgroup Management list, each member's row link in the User Assignment list, the per-row Clear override link in User Assignment Details) are explicitly out of scope for accesskeys, since a distinct static key cannot sensibly be assigned per dynamically-generated row. These remain reachable via standard screen reader list/link navigation.
* The letters D, E, and F are never used, since Chrome on Windows intercepts Alt+D (address bar focus) and Alt+E / Alt+F (Chrome's own menu) before the keypress reaches the page.
* Each letter maps to exactly one action *type*, and that mapping never changes based on which page or view the control appears on — a control's accesskey is determined solely by what kind of action it performs, not by its surrounding context. Two controls with different accesskeys never appear on screen at the same time, so there is no risk of ambiguity.

Global mapping:

* S — Save Changes
* T — Reset
* C — Create / Create new subgroup
* U — Update
* D — Delete this subgroup
* Y — Yes, delete / Yes, remove (confirmation actions)
* L — Cancel
* N — Sync
* H — Search
* R — Remove Selected
* A — Add Selected / Add Groups (link)
* B — Back (to list / to details)

Per-page/view assignment:

* Feature Controls: S (Save Changes), T (Reset)
* Subgroup Management, List view: C (Create new subgroup), N (Sync)
* Subgroup Management, Create view: B (Back to Subgroup Management), C (Create), N (Sync)
* Subgroup Management, Details view: B (Back to Subgroup Management), U (Update), D (Delete this subgroup), N (Sync); while the inline delete confirmation is showing, D is replaced on screen by Y (Yes, delete this subgroup) and L (Cancel)
* User Assignment, List view: H (Search), N (Sync)
* User Assignment, Details view: B (Back to User Assignment), H (Search), R (Remove Selected), A (Add Groups link), N (Sync); while the inline parent-group-removal confirmation is showing, R is replaced on screen by Y (Yes, remove from the parent group) and L (Cancel)
* User Assignment, Add Groups view: B (Back to Details), H (Search), A (Add Selected), N (Sync)
