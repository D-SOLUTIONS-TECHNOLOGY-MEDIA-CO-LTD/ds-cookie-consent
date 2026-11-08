<?php
/**
 * Integration bootstrap: WordPress test suite shipped in the wp-env tests container.
 */

$dscc_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $dscc_tests_dir ) {
	$dscc_tests_dir = '/wordpress-phpunit';
}

$dscc_plugin_dir = dirname( __DIR__, 3 );

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $dscc_plugin_dir . '/vendor/yoast/phpunit-polyfills' );
}

require_once $dscc_tests_dir . '/includes/functions.php';

/**
 * Query filter: CREATE TABLE becomes CREATE TEMPORARY TABLE (same rule as the WordPress test case class).
 *
 * @param string $query SQL.
 * @return string
 */
function dscc_tests_create_temporary_tables( $query ) {
	if ( 'CREATE TABLE' === substr( trim( $query ), 0, 12 ) ) {
		return substr_replace( trim( $query ), 'CREATE TEMPORARY TABLE', 0, 12 );
	}
	return $query;
}

tests_add_filter(
	'after_setup_theme',
	static function () {
		remove_filter( 'query', 'dscc_tests_create_temporary_tables' );
		// Temporary tables belong to one database connection and every test class reconnects, so the tables made while
		// the plugin booted are gone by the first test. Forget the schema version too: a class starts without a schema,
		// as it did before the upgrade moved to the first request, and installs the tables it needs itself.
		delete_option( 'dscc_db_version' );
	}
);

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $dscc_plugin_dir ) {
		// The plugin upgrades its schema on the first request (plugins_loaded). Test cases turn CREATE TABLE into
		// temporary-table statements; do the same while the plugin boots, otherwise real tables would be created here
		// and tests that drop tables (uninstall) could not remove them. The filter is removed after plugins_loaded.
		add_filter( 'query', 'dscc_tests_create_temporary_tables' );
		require $dscc_plugin_dir . '/ds-cookie-consent.php';
	}
);

require $dscc_tests_dir . '/includes/bootstrap.php';
