---
applyTo: "includes/**/*.php"
---

# UI and Accessibility Instructions

This project is built by and for a blindness-focused nonprofit. A UI change
that isn't fully usable with a screen reader is a defect, not an acceptable
tradeoff — per `docs/ux-accessibility.md` and `CLAUDE.md`'s Accessibility by
Design section. This applies even to admin-only screens, since BITS's own
staff and volunteers may include blind or low-vision users.

Not every file under `includes/` renders UI (the Groups.io API client, the
subgroup cache, and the audit table schema don't) — apply this file's
guidance when you're actually looking at rendering/markup code, and skip it
otherwise.

## Standard

* Target: WCAG 2.1, Level AA, for every UI surface this plugin introduces.
* In-scope UI surfaces (per `docs/ux-accessibility.md` section 3): the admin
  settings screen, the level-specific mandatory groups meta box, the magic
  link self-service request form, the mass-action anomaly pending-approval
  admin UI, the per-member audit view and queue-health dashboard.
  Explicitly out of scope: any UI for selecting/changing mailing list
  preferences — that lives entirely in pre-existing PMPro forms outside
  this project.
* No automated test verifies WCAG compliance — that's a manual screen
  reader verification step, not something CI enforces. Don't claim a UI
  change is "tested" based on unit tests passing alone.

## Established Patterns (follow these, don't reinvent per-field)

Based on `includes/Settings.php`, the reference implementation for this
project's accessible-admin-form pattern:

* Use the WordPress Settings API (`register_setting`/`add_settings_section`/
  `add_settings_field`) rather than hand-rolled form markup where possible —
  it gets native `<label>` association, fieldset grouping, and the standard
  "Settings saved" admin notice accessible by default.
* Every field label is wrapped in a real `<label for="...">` pointing at the
  field's `id` — never a bare string passed to `add_settings_field()`.
* Every field has a visible, short helper description explaining its
  purpose/unit/effect, in a `<p class="description" id="{field_id}_description">`,
  wired to the control via `aria-describedby="{field_id}_description"` —
  not just a visual-only description a screen reader would skip.
* The first field on a settings page gets `autofocus` (and only the first
  one) so keyboard/screen-reader users land on the form immediately, rather
  than wherever the browser chrome happens to put focus.
* All dynamic output in rendered markup is escaped with the correct
  WordPress escaping function for its context (`esc_attr()` for attribute
  values — including ones that are always one of a small fixed set of
  internal strings, like an `autofocus` flag fragment, since WPCS's
  `OutputNotEscaped` sniff doesn't distinguish; `esc_html()`/`esc_html__()`
  for text content; `esc_textarea()` for textarea content).
* A disabled control that represents "not implemented yet" (see the
  settings screen's "Run Dry-Run Now" stub) still gets a real
  `aria-describedby` description explaining why it's disabled and when it
  will be available — a disabled button with no explanation is a dead end
  for a screen reader user who can't infer the reason visually.

## Additional Requirements Worth Calling Out

* Rate-limit or error states (e.g., the magic link request form) must be
  clearly announced to assistive tech, but must never reveal more specific
  information than the generic response would — the accessible experience
  must not become a side channel for enumeration attacks. Coordinate with
  `docs/security.md`'s enumeration mitigation before changing any
  user-facing response text on that form.
* Never convey severity (e.g., critical alert vs. informational) through
  color alone — admin alert emails and any status UI need a text/structural
  cue too.
* When a UI surface lists multiple items with per-item actions (e.g., the
  mass-action anomaly pending-approval queue), each action's accessible
  name must unambiguously identify which item it applies to — don't rely on
  visual table position or grouping alone to convey that association.

## Before Calling a UI Change Done

* A screen reader pass by the primary contributor is the default local
  verification step for any change touching a listed UI surface — flag in
  the PR description whether this has happened, per `CONTRIBUTING.md`, and
  don't assume it's implied by CI being green.
* A fuller screen reader matrix (NVDA, JAWS, VoiceOver) is required before
  merging any such change to `dev` and is mandatory for Phase 8's exit
  criterion — this is separate from, and in addition to, the local pass.
