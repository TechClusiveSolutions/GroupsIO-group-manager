# Groups.io Group Manager — Documentation

## What This Plugin Is

This is a WordPress plugin built for Blind Information Technology Solutions (BITS), a blindness-focused nonprofit and professional affiliate of the American Council of the Blind. It runs on the BITS WordPress.com workspace, alongside Paid Memberships Pro (PMPro), which BITS uses to manage organizational membership.

## What It Does

The plugin automates BITS's Groups.io email list membership so no one has to manage it by hand:

* When a member's PMPro membership begins or changes, the plugin reads the mailing list selections already captured on the member's existing PMPro profile and adds the member to the corresponding BITS Groups.io mailing lists — both lists every member must belong to, and any lists tied to their specific membership level.
* When a member's PMPro membership ends — through expiration, cancellation, or account deletion — the plugin automatically removes the member from every BITS Groups.io mailing list they were part of.
* A nightly reconciliation job checks for drift between what PMPro says a member's access should be and what Groups.io actually reflects, correcting any mismatch.
* A passwordless "magic link" feature lets a member reach their WordPress profile by email, without needing to recall a password.

## Why It Exists

Before this plugin, keeping BITS's Groups.io mailing lists in sync with actual paid membership status required manual administration — someone had to notice when a membership lapsed or a new member joined, and update Groups.io by hand. This plugin removes that manual step entirely, so that only active, paying members ever have access to member-only communication channels, and no one is left subscribed after their membership ends or missed after they join.

## Brief Usage Overview

This plugin does not introduce its own mailing-list preference form — members choose and update which lists they want to join entirely through PMPro's existing profile fields, unrelated to this plugin. Once that preference data exists, this plugin takes over automatically: it reacts to PMPro membership events in the background and keeps Groups.io in sync, with no day-to-day action required from members or administrators.

Administrators interact with the plugin through a settings screen for its own operational configuration — mandatory mailing lists, the grace period before removal on a lapsed membership, log retention, and an emergency kill switch to halt all sync activity — plus an audit log of every add/remove action the plugin has taken.

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
