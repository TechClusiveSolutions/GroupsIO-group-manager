# Acceptance Criteria Standard

## BITS Groups.io Membership Sync

### 1. Purpose

This document specifies the required format for acceptance criteria on Feature- and Bug-type GitHub issues, per `CLAUDE.md`'s Issue Tracking section, and gives worked examples specific to this project.

### 2. Feature-Type Format

* Written as one or more Given/When/Then scenarios.
* At least one scenario must be accessibility-focused — specifically exercising the feature via a screen reader, not merely asserting that a screen reader "should" work.
* Scenarios are written and confirmed in the issue before implementation starts; they are not filled in retroactively after the code exists.

**Worked example** (Phase 4, join sync):

> Given a member with `list_subscriptions` selecting subgroups A and B, and a PMPro level whose global and level-specific mandatory groups include subgroup C,
> When the member's `pmpro_after_all_membership_level_changes` event fires for a new join,
> Then the member is added to subgroups A, B, and C on Groups.io, and an audit log entry records a successful add for each.
>
> Given the same scenario as above,
> When an administrator navigates to the member's audit view using a screen reader,
> Then the three add entries are announced clearly with subgroup, outcome, and timestamp, without relying on any visual-only table structure to convey which value belongs to which entry.

### 3. Bug-Type Format

* Written as Steps to Reproduce, Expected behavior, Actual behavior, and at least one Given/When/Then scenario capturing the regression test's intent.

**Worked example** (hypothetical):

> Steps to Reproduce: a member with two `list_subscriptions` entries sharing the same subgroup ID but different emails has their membership cancelled.
> Expected: both `(subgroup id, email)` pairs are removed from Groups.io.
> Actual: only one of the two emails is removed; the second remains subscribed under the stale alias email.
>
> Given a member with two `list_subscriptions` entries for the same subgroup ID under different emails,
> When their membership is cancelled and the grace period elapses,
> Then both emails are removed from that subgroup, verified via the Groups.io test group.

### 4. Task, Planning, Research, and Testing-Type Issues

* Formal acceptance criteria in the Given/When/Then or Steps-to-Reproduce format are not required, per `CLAUDE.md` and the Definition of Done document.
* These issue types instead state a clear, checkable exit condition in the issue description itself (e.g., "Outcome: `docs/security.md` section 4 is drafted and confirmed section by section with the primary contributor").

### 5. Where Acceptance Criteria Live

* Acceptance criteria live in the GitHub issue itself (the "Docs" field on the issue links back to the relevant PRD/phase-plan section they derive from), not solely in a design document or in conversation — this is what "before implementation starts" requires per `CLAUDE.md`'s Order of Operations.
