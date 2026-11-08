<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Database\Schema;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * uninstall.php: plugin options and the cron job are always removed; the log table and the
 * log capability are removed only when the admin opted in via advanced.delete_data_on_uninstall.
 */
class Dscc_Uninstall_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::activate();
	}

	public function tear_down() {
		// Dropping the table is DDL and cannot be rolled back, so reinstall for the next test.
		Activator::activate();
		parent::tear_down();
	}

	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'ds-cookie-consent/ds-cookie-consent.php' );
		}
		require dirname( __DIR__, 3 ) . '/uninstall.php';
	}

	private function table_exists() {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::log_table() ) );
	}

	private function named_table_exists( $table ) {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function stored_caps() {
		global $wpdb;
		$stored = get_option( $wpdb->prefix . 'user_roles' );
		return $stored['administrator']['capabilities'];
	}

	public function test_default_uninstall_removes_options_and_cron_but_keeps_log_table() {
		set_transient( 'dscc_rl_' . md5( 'LittleD' ), array( 'n' => 3, 't' => time() ), 60 );
		update_option( 'dscc_resolved', array( 'key' => 'x' ), true );
		update_option( 'dscc_blocked_daily', array( gmdate( 'Y-m-d' ) => 2 ), false );
		update_option( 'dscc_frontend_catalog', array( 'v' => 1 ), true );
		update_option( 'dscc_known_db_meta', array( 'version' => 'x' ), false );
		set_transient( 'dscc_new_cookies', 3, 60 );
		set_site_transient( 'dscc_gh_release', array( 'repo' => 'LittleD/x' ), 60 );
		set_transient( 'dscc_import_1_LittleDtoken', array( 'doc' => array() ), 60 );
		$this->run_uninstall();
		wp_cache_flush(); // The raw DELETE cannot invalidate individual cache entries.

		$this->assertFalse( get_option( 'dscc_resolved' ) );
		$this->assertFalse( get_option( 'dscc_blocked_daily' ) );
		$this->assertFalse( get_option( 'dscc_frontend_catalog' ) );
		$this->assertFalse( get_option( 'dscc_known_db_meta' ) );
		$this->assertFalse( get_transient( 'dscc_new_cookies' ) );
		$this->assertFalse( get_site_transient( 'dscc_gh_release' ) );
		$this->assertFalse( get_transient( 'dscc_import_1_LittleDtoken' ), 'stored import previews are removed' );
		$this->assertTrue( $this->named_table_exists( Schema::services_table() ) && $this->named_table_exists( Schema::cookies_table() ), 'the catalog is the admin\'s work: kept unless opted in' );
		$this->assertFalse( get_transient( 'dscc_rl_' . md5( 'LittleD' ) ), 'rate-limit buckets are removed' );
		$this->assertArrayNotHasKey( 'dscc_view_log', $this->stored_caps(), 'the capability is removed even when the log table is kept' );
		$this->assertFalse( get_option( 'dscc_settings' ) );
		$this->assertFalse( get_option( 'dscc_db_version' ) );
		$this->assertFalse( wp_next_scheduled( 'dscc_cleanup_log' ) );
		$this->assertTrue( $this->table_exists(), 'log table must survive unless the admin opted in' );
	}

	public function test_opt_in_uninstall_drops_log_table_and_capability() {
		global $wpdb;
		Settings::save_group( 'advanced', array( 'delete_data_on_uninstall' => true ) );
		$this->run_uninstall();

		$this->assertFalse( $this->table_exists(), 'log table must be dropped after opt-in uninstall' );
		$this->assertFalse( $this->named_table_exists( Schema::services_table() ) || $this->named_table_exists( Schema::cookies_table() ), 'catalog tables are dropped too' );

		// Read the stored roles: WP_Roles::remove_cap() updates the option, while get_role() returns a cached object.
		$stored = get_option( $wpdb->prefix . 'user_roles' );
		$this->assertArrayNotHasKey( 'dscc_view_log', $stored['administrator']['capabilities'] );
	}
}
