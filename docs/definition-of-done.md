# Definition of Done

## BITS Groups.io Membership Sync

### 1. Purpose

This document specifies, per issue type, the conditions that must be true before an issue is marked complete, per `CLAUDE.md`'s Issue Tracking section.

### 2. Common to Every Issue Type

* All fields required by `CLAUDE.md`'s Issue Tracking section are populated (assignee, status, priority, labels, milestone, project, parent/children/siblings or explicit "none", type, estimated completion, subtype, phase, docs link).
* Any code change passes CI in full: documentation lint, code lint, static analysis, unit tests, the 80% coverage gate, and integration tests (per `docs/ci.md`).
* The pull request is reviewed, with all review threads resolved, and merge-authorized per the project's working agreement (verbal confirmation from the primary contributor, per `docs/ci.md` section 2), before merging to `dev`.

### 3. Feature-Type Issues

* Formal acceptance criteria (Given/When/Then), including at least one accessibility-focused scenario, are written and confirmed before implementation starts, per `CLAUDE.md`.
* Every acceptance criterion is demonstrably satisfied.
* A corresponding unit test and integration test exist for the feature, per the Testing Standard document.
* If the feature touches a UI surface listed in `docs/ux-accessibility.md` section 3, it has passed the default local screen reader verification (the fuller matrix pass is reserved for the Phase 9 gate, not every individual feature issue).
* If the feature touches stored content, credentials, authentication, or the Groups.io API, it has been checked against `docs/security.md`'s relevant section(s), per `CLAUDE.md`'s Security Gate.
* Any documentation (`PRD.md`, `phase-plan.md`, `security.md`, etc.) affected by the feature is updated to match what was actually built, if it diverged from what was originally documented.

### 4. Bug-Type Issues

* Formal acceptance criteria (Steps to Reproduce / Expected / Actual / Given-When-Then) are written and confirmed before implementation starts, per `CLAUDE.md`.
* A regression test exists that would have caught the bug, and passes against the fix.
* The full existing test suite still passes — a bug fix does not regress other behavior.
* The fix is scoped to the bug; unrelated refactoring or cleanup is not bundled into the same pull request (per `CLAUDE.md`'s general engineering conventions).

### 5. Task-Type Issues

* No formal acceptance criteria are required, per `CLAUDE.md`, but the issue's stated exit condition (whatever it says the task accomplishes) is demonstrably met.
* If the task produces code, the Common conditions in section 2 still apply in full.

### 6. Planning, Research, and Testing-Type Issues

* No formal acceptance criteria are required, per `CLAUDE.md`.
* **Planning issues**: the outcome is a documented decision or shared understanding (e.g., a section of a design document confirmed, a phase-plan entry finalized), not code.
* **Research issues**: findings are documented somewhere durable (a design doc, a PRD open-item resolution, or a briefing document) rather than left only in conversation.
* **Testing issues**: the specified test coverage is added or improved and passes in CI; this does not itself require new acceptance criteria beyond what the issue specifies.

### 7. Phase-Level "Definition of Done" vs. Issue-Level

* An individual issue being "done" per this document is necessary but not always sufficient for a phase to be considered complete — each phase in `phase-plan.md` carries its own exit criterion, which may require multiple issues to be done collectively (e.g., Phase 9's exit criterion requires the fuller screen reader matrix pass and the full security checklist verification, neither of which is scoped to a single issue).
