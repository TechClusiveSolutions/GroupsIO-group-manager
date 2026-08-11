#!/usr/bin/env bash
# Dev-only tooling for switching the wp-env dev site between mocked
# test data and the real dedicated test Groups.io group, and for
# resetting/repopulating the local member/group index in between.
#
# Never touches the production BITS Groups.io group - "use-test-group"
# and "populate" only ever read credentials from the GROUPS_IO_TEST_API_KEY/
# GROUPS_IO_TEST_PARENT_GROUP environment variables, the same test-group
# credential this project's CI integration workflow (integration.yml)
# already uses. There is no code path here that accepts or defaults to
# any other credential.
#
# This is external dev tooling only, not part of the plugin's shipped
# code - an in-plugin version of this capability (an admin-facing
# "reset/repopulate from test group" control) is scoped as post-release
# future work; see docs/phase-plan.md.
set -eo pipefail

usage() {
	cat <<'USAGE'
Usage: bin/dev-groupsio-data.sh <command>

Commands:
  reset            Wipe all local member/group data (every row in the
                    bits_groupsio_member_index table, including parent-group
                    rows) from the dev site's database. Does not touch
                    Groups.io itself - this only clears the local index.

  use-test-group    Point the dev site's Groups.io credentials at the real
                    dedicated test Groups.io group, using the
                    GROUPS_IO_TEST_API_KEY and GROUPS_IO_TEST_PARENT_GROUP
                    environment variables (must already be set - the same
                    credential this project's integration.yml CI workflow
                    uses). Once set, the dev site's local API mock no
                    longer intercepts Groups.io calls - direct_add()/
                    remove_member()/sync() etc. make real network calls
                    against the real test group.

  use-mock         Revert the dev site's Groups.io credentials back to
                    this project's established fake dev key, re-enabling
                    the local API mock (tests/e2e/mu-plugins/groupsio-api-mock.php).

  populate         Runs MemberIndex::sync() once against whichever
                    Groups.io group is currently configured. Run
                    use-test-group first if you want this to pull real
                    test-group data rather than re-syncing the mock's own
                    fixture state.

Typical real-data test cycle:
  bin/dev-groupsio-data.sh reset
  bin/dev-groupsio-data.sh use-test-group
  bin/dev-groupsio-data.sh populate
  ... test against real test-group data ...
  bin/dev-groupsio-data.sh reset
  bin/dev-groupsio-data.sh use-mock
USAGE
}

require_wp_env() {
	if ! command -v npx >/dev/null 2>&1; then
		echo "npx not found on PATH - if using nvm, run: source ~/.nvm/nvm.sh" >&2
		exit 1
	fi
}

cmd_reset() {
	require_wp_env
	npx wp-env run cli wp eval 'global $wpdb; $wpdb->query( "TRUNCATE TABLE " . \BITS\GroupsIOSync\MemberIndex::table_name() );'
	echo "Local member/group index wiped. Groups.io itself was not touched."
}

cmd_use_test_group() {
	require_wp_env
	: "${GROUPS_IO_TEST_API_KEY:?Set GROUPS_IO_TEST_API_KEY in your environment first (the same credential integration.yml uses).}"
	: "${GROUPS_IO_TEST_PARENT_GROUP:?Set GROUPS_IO_TEST_PARENT_GROUP in your environment first (the same value integration.yml uses).}"
	npx wp-env run cli wp config set GROUPS_IO_API_KEY "$GROUPS_IO_TEST_API_KEY" --type=constant
	npx wp-env run cli wp config set GROUPS_IO_PARENT_GROUP "$GROUPS_IO_TEST_PARENT_GROUP" --type=constant
	echo "Dev site now points at the real test Groups.io group. The local mock will no longer intercept API calls - real network calls will be made."
}

cmd_use_mock() {
	require_wp_env
	npx wp-env run cli wp config set GROUPS_IO_API_KEY 'fake-local-dev-key-not-real' --type=constant
	npx wp-env run cli wp config set GROUPS_IO_PARENT_GROUP 'bits-local-dev' --type=constant
	echo "Dev site reverted to mocked test data (tests/e2e/mu-plugins/groupsio-api-mock.php)."
}

cmd_populate() {
	require_wp_env
	npx wp-env run cli wp eval '\BITS\GroupsIOSync\MemberIndex::sync(); echo "Synced from the currently configured Groups.io group." . PHP_EOL;'
}

case "${1-}" in
	reset) cmd_reset ;;
	use-test-group) cmd_use_test_group ;;
	use-mock) cmd_use_mock ;;
	populate) cmd_populate ;;
	*) usage; exit 1 ;;
esac
