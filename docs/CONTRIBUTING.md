# Contributing

Thank you for your interest in contributing to the BITS Groups.io Membership Sync plugin.

## Before You Start

* This project follows a strict Plan → Design → Track → Implement → Test order of operations. No pull request will be accepted for work that skipped design confirmation or doesn't correspond to a tracked GitHub issue with complete acceptance criteria.
* Check open issues before starting work, to avoid duplicate effort. If no issue exists for what you want to work on, open one first and wait for it to be scoped and confirmed before writing code.

## Branching

* `dev` is the integration branch; `main` is the release branch. Cut your branch from `dev`, not `main`.
* Both `dev` and `main` are protected: no direct pushes, no force pushes, no branch deletion, administrators are not exempt.
* Branch names use one of four prefixes, checked by CI:
  * `feature/*` — new functionality.
  * `bug/*` — bug fixes.
  * `docs/*` — documentation-only changes.
  * `infra/*` — tooling, CI, and other infrastructure work that isn't itself part of the plugin's shipped behavior.

## Making a Change

1. Confirm a GitHub issue exists for your change, with complete acceptance criteria (Given/When/Then for features, including at least one accessibility-focused scenario; Steps to Reproduce/Expected/Actual/Given-When-Then for bugs).
2. Create a branch from `dev` using the appropriate prefix above.
3. Write your code, following the project's linting (PHP_CodeSniffer/WPCS) and static analysis (PHPStan) configuration.
4. Write the corresponding unit test and, where applicable, integration test.
5. If your branch is `feature/*` or `bug/*`, add an entry to `CHANGELOG.md`'s `## [Unreleased]` section under the matching subheading (`### Added`/`### Changed` for features, `### Fixed`/`### Security` for bug fixes). `docs/*` and `infra/*` branches are exempt — this isn't user-facing plugin behavior.
6. Open a pull request against `dev`. CI must pass in full: documentation lint, code lint, static analysis, unit tests, the coverage gate, integration tests, the changelog check, and the branch-name check.
7. If your change touches a UI surface, include a note confirming screen reader verification (see the project's accessibility standard).
8. If your change touches stored content, credentials, authentication, or the Groups.io API, note that it has been checked against the project's security document.

## Accessibility

This project is built by and for a blindness-focused nonprofit. Every UI surface must be fully navigable with a screen reader and meet WCAG 2.1 Level AA. This is not optional and not a follow-up task — a pull request introducing an inaccessible UI surface will not be merged as-is.

## Code of Conduct

By participating in this project, you agree to abide by the [Code of Conduct](CODE_OF_CONDUCT.md).

## License

By contributing, you agree that your contributions will be licensed under the project's Apache License 2.0 (see `LICENSE` at the repository root).
