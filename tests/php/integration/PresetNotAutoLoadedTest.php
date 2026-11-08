<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Database\Schema;

/**
 * presets/d-solutions-standard.json is imported explicitly (Import UI), never on a fresh activation.
 */
class Dscc_Preset_Not_Auto_Loaded_Test extends WP_UnitTestCase {

	public function test_fresh_activation_leaves_the_catalog_empty_and_the_bundled_preset_unloaded() {
		global $wpdb;
		Activator::activate();

		$services = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::services_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cookies  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::cookies_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 0, $services, 'no service row is created by activation' );
		$this->assertSame( 0, $cookies, 'no cookie row is created by activation' );
		$this->assertSame( array(), FrontendCatalogCache::services(), 'visitors get no vendors until an admin ticks or imports' );
	}
}
