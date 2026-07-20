# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-07-20

### Added

- Plugin scaffold: bootstrap, audit table (`bits_groupsio_audit`), and CI.
- Admin settings screen (Settings > BITS Groups.io Sync) for the database-backed
  operational settings: global mandatory groups, grace period, log retention
  policy, kill switch, mass-action anomaly threshold, magic link rate limits,
  and reconciliation dry-run mode, with clamped numeric bounds.
- Level-specific mandatory groups meta box on the PMPro Edit Membership Level
  screen.
- Per-field accessibility descriptions (`aria-describedby`), native label
  association, and autofocus on the first field of the settings screen.
- A Reset button on the settings screen, and a disabled "Run Dry-Run Now"
  stub ahead of the Phase 5 reconciliation engine.

### Fixed

- `Settings::sanitize_lines()` no longer corrupts array input by casting it
  to the literal string `"Array"`; array elements are now treated as
  individual lines.
- Unescaped `autofocus` attribute output on the settings screen (WPCS
  `OutputNotEscaped`).
