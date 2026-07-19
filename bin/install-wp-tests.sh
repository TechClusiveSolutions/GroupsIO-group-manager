#!/usr/bin/env bash
# Installs the WordPress core PHPUnit test suite and a matching WP core
# copy into temp directories, and creates the test database. Standard
# script long-shipped with WordPress core plugin scaffolding tools.
set -eo pipefail

if [ $# -lt 3 ]; then
	echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version]"
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() {
	if [ "$(which curl)" ]; then
		curl -s "$1" > "$2"
	elif [ "$(which wget)" ]; then
		wget -nv -O "$2" "$1"
	fi
}

install_wp() {
	mkdir -p "$WP_CORE_DIR"

	if [ "$WP_VERSION" == "latest" ]; then
		local ARCHIVE_NAME='latest'
	else
		local ARCHIVE_NAME="wordpress-$WP_VERSION"
	fi

	download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" /tmp/wordpress.tar.gz
	tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
	download https://raw.github.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/wp-content/db.php"
}

install_test_suite() {
	local ABSPATH_ESC=$(echo "$WP_CORE_DIR" | sed 's/\//\\\//g')
	local wpdb_prefix="wp_"

	mkdir -p "$WP_TESTS_DIR"
	svn export --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ "$WP_TESTS_DIR/includes" --force 2>/dev/null || \
		( mkdir -p "$WP_TESTS_DIR/includes" && download https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/tests/phpunit/includes/functions.php "$WP_TESTS_DIR/includes/functions.php" && download https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/tests/phpunit/includes/bootstrap.php "$WP_TESTS_DIR/includes/bootstrap.php" )
	svn export --quiet https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ "$WP_TESTS_DIR/data" --force 2>/dev/null || true

	download https://raw.githubusercontent.com/WordPress/wordpress-develop/trunk/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s:dirname( __FILE__ ) . '/src':'${ABSPATH_ESC}':" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
	sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
}

install_db() {
	mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
}

install_wp
install_test_suite
install_db
