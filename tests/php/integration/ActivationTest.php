<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Database\Schema;

class Dscc_Activation_Test extends WP_UnitTestCase {

	public function test_activation_creates_table_capability_options_and_cron() {
		global $wpdb;
		Activator::activate();

		$table = Schema::log_table();
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );

		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array( 'consent_id', 'choice', 'analytics', 'marketing', 'services_json', 'policy_version', 'lang', 'page_url', 'ip_masked', 'user_agent', 'src', 'created_at' ) as $col ) {
			$this->assertContains( $col, $columns );
		}

		$this->assertTrue( get_role( 'administrator' )->has_cap( 'dscc_view_log' ) );
		$settings = get_option( 'dscc_settings' );
		$this->assertSame( 'card', $settings['appearance']['layout'] );
		$this->assertSame( Schema::VERSION, get_option( 'dscc_db_version' ) );
		$this->assertNotFalse( wp_next_scheduled( 'dscc_cleanup_log' ) );
	}

	public function test_policy_version_is_persisted_when_the_option_is_missing() {
		delete_option( 'dscc_settings' );
		$this->assertFalse( get_option( 'dscc_settings' ) );
		do_action( 'init' );
		$stored = get_option( 'dscc_settings' );
		$this->assertIsArray( $stored );
		$this->assertSame( gmdate( 'Y-m' ), $stored['advanced']['policy_version'] );
	}

	public function test_deactivation_clears_cron_but_keeps_data() {
		Activator::activate();
		Activator::deactivate();
		$this->assertFalse( wp_next_scheduled( 'dscc_cleanup_log' ) );
		$this->assertNotFalse( get_option( 'dscc_settings' ) );
	}
}
