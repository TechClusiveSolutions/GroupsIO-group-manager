# E2E Tests (Playwright)

Browser-driven tests against the admin UI, covering every feature
implemented so far (the GroupsIO Management menu scaffold, Feature
Controls, Subgroup Management). See `../../docs/testing-standard.md`
for how this fits alongside the PHPUnit unit and integration suites.

## What's mocked, and why

These tests do not call the real Groups.io API. A WordPress mu-plugin,
`mu-plugins/groupsio-api-mock.php`, is loaded only into the wp-env dev
and test environments (see `.wp-env.json`'s `mu-plugins` mapping) and
intercepts any outbound call to `groups.io/api` while `GROUPS_IO_API_KEY`
is one of this project's established fake dev/test values - it never
activates for a real credential. Everything else in the request path is
real: WordPress login, nonces, the plugin's own `GroupsIoApiClient`,
response handling, read-back verification, cache invalidation,
redirects, and notices.

## Running locally

```sh
npm install
npx wp-env start
npx playwright install --with-deps chromium   # first run only
npm run e2e
```

`npm run e2e:report` opens the last HTML report (screenshots/traces on
failure).

Tests run against `http://localhost:8888` by default (`playwright.config.ts`'s
`baseURL`); override with the `WP_BASE_URL` environment variable if
your wp-env instance runs elsewhere (e.g. through an SSH tunnel, per
this project's documented development setup on phoenix).

## Adding new tests

Auth happens once, in `global-setup.ts`, and is reused across every
spec file via Playwright's `storageState` mechanism - don't log in
inside an ordinary test. The one exception is `tests/e2e/tests/end-to-end.spec.ts`,
which deliberately starts unauthenticated to cover the full
login-through-a-Groups.io-update path this project requires at least
one test to exercise.
