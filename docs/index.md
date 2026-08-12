# Groups.io Group Manager — Documentation

## What This Plugin Is

This is a WordPress plugin built for Blind Information Technology Solutions (BITS), a blindness-focused nonprofit and professional affiliate of the American Council of the Blind. It runs on the BITS WordPress.com workspace, alongside Paid Memberships Pro (PMPro), which BITS uses to manage organizational membership.

## What It Does

The plugin's eventual goal is to automate BITS's Groups.io email list membership so no one has to manage it by hand. That fully automatic side is planned but not yet built (see `phase-plan.md`'s Phase 4 onward):

* When a member's PMPro membership begins or changes, the plugin will read the mailing list selections already captured on the member's existing PMPro profile and add the member to the corresponding BITS Groups.io mailing lists — both lists every member must belong to, and any lists tied to their specific membership level.
* When a member's PMPro membership ends — through expiration, cancellation, or account deletion — the plugin will automatically remove the member from every BITS Groups.io mailing list they were part of.
* A nightly reconciliation job will check for drift between what PMPro says a member's access should be and what Groups.io actually reflects, correcting any mismatch.
* A passwordless "magic link" feature will let a member reach their WordPress profile by email, without needing to recall a password.

What's built and shipped today, per the Phase 3 scope amendment (`phase-plan.md`), is the admin-facing management layer described in the Brief Usage Overview below: direct Groups.io subgroup administration, and manual per-member group assignment with full audit logging. This isn't a stand-in for the automatic sync above — it's a permanent part of v1 in its own right, for administering subgroups directly and intervening when automation misbehaves or hasn't shipped yet.

## Why It Exists

Before this plugin, keeping BITS's Groups.io mailing lists in sync with actual paid membership status required manual administration — someone had to notice when a membership lapsed or a new member joined, and update Groups.io by hand. This plugin's goal is to remove that manual step entirely, so that only active, paying members ever have access to member-only communication channels, and no one is left subscribed after their membership ends or missed after they join. Until the automatic sync above ships, the GroupsIO Management admin area (below) is how that administration actually happens today — deliberately manual, but centralized, audited, and no longer scattered across the Groups.io site itself.

## Brief Usage Overview

This plugin does not introduce its own mailing-list preference form — members' desired lists will eventually come from PMPro's existing profile fields, unrelated to this plugin, once the automatic sync (above) is built. Today, list assignment happens entirely through administrator action on the pages below.

Administrators interact with the plugin through a "GroupsIO Management" admin menu, with three pages. A "Sync" button appears on every view of all three pages, letting an administrator force any currently-queued add/remove jobs to process immediately rather than waiting on the next scheduled background run:

* **Feature Controls** — the plugin's own operational configuration: mandatory mailing lists, the grace period before removal on a lapsed membership, log retention, and an emergency kill switch to halt all sync activity. Every queued add/remove action, and every settings change made on this page, is recorded to an audit log; a dedicated view for reading that log back (a per-member audit view) is planned for a later phase and not yet built.
* **Subgroup Management** — direct administration of the underlying Groups.io subgroups themselves (as opposed to member sync, which is automatic): a List view showing the parent group's address and every subgroup as a link to its own address, a Create view (name, an optional cosmetic title, and an optional description), and a Details view per subgroup to view its live member list, edit its name/title/description, and delete it.
* **User Assignment** — a paginated, searchable List view of every member and their total Groups.io group count; a per-member Details view to review their current group membership, queue a removal (with an extra confirmation step before removing them from the parent group, since that removes all of their BITS Groups.io access at once), or clear a manual override; and an Add Groups view to queue adding the member to any group they're not currently in. Adding a member to a subgroup automatically also queues adding them to the parent group if they aren't already a member of it, since subgroup membership isn't meaningful without it. The group's Groups.io owner can never be queued for removal from the parent group specifically (a dedicated safeguard, independent of and in addition to the confirmation step above) — removing the owner from an individual subgroup is still allowed. Suspend (as opposed to add/remove) remains deferred to a later phase.

## Where to Go Next

* [Product Requirements Document](PRD.md) — what this plugin is required to do, and why, in full detail.
* [Phase Plan](phase-plan.md) — how the build is sequenced, phase by phase.
* [Security Document](security.md) — threat model, credential handling, and data-at-rest policy.
* [Testing Standard](testing-standard.md) — how the plugin is tested.
* [Implementation Standard](implementation-standard.md) — tooling and implementation-level conventions.
* [CI Document](ci.md) — what runs in continuous integration, and what it gates.
* [UX/Accessibility Document](ux-accessibility.md) — the accessibility standard this plugin is held to.
* [Definition of Done](definition-of-done.md) and [Acceptance Criteria Standard](acceptance-criteria-standard.md) — what "done" means for a given unit of work.
* [Contributing](CONTRIBUTING.md) and [Code of Conduct](CODE_OF_CONDUCT.md) — for anyone looking to contribute to this project.
