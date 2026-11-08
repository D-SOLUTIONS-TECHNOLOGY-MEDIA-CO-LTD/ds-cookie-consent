<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Frontend\ClientData;
use DSolutions\CookieConsent\Frontend\Renderer;
use DSolutions\CookieConsent\Frontend\ViewModel;
use DSolutions\CookieConsent\Scanner\AlertStore;
use DSolutions\CookieConsent\Scanner\BeaconCollector;
use DSolutions\CookieConsent\Scanner\BeaconEndpoint;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * The beacon endpoint: opt-in, validation, limits, merge into the catalog, leak alerts, no extra front-end queries.
 */
class Dscc_Beacon_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
		FindingStore::reset();
		FrontendCatalogCache::rebuild();
		delete_option( AlertStore::OPTION );
		delete_transient( BeaconCollector::BUDGET_TRANSIENT );
		$this->enable( true );
		$_SERVER['HTTP_ORIGIN'] = home_url();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		wp_set_current_user( 0 );
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	private function enable( $on, $rate = 5 ) {
		Settings::save_group(
			'scanner',
			array_merge(
				Settings::group( 'scanner' ),
				array(
					'beacon_enabled' => $on ? '1' : '',
					'beacon_sample'  => $rate,
				)
			)
		);
	}

	private function payload( array $over = array() ) {
		return wp_json_encode(
			array_merge(
				array(
					'c' => array( '_ga', '_fbp', 'LittleD_unknown' ),
					'l' => array( 'LittleD_key' ),
					'x' => array(),
					'h' => array( 'www.clarity.ms', 'cdn.littled.example' ),
					's' => array(
						'c' => array( 'analytics' => 1, 'marketing' => 0 ),
						's' => array(),
					),
					'u' => '/shop/?utm=1',
				),
				$over
			)
		);
	}

	public function test_off_by_default_and_when_the_rate_is_zero() {
		$this->enable( false );
		$this->assertSame( 403, BeaconEndpoint::process( $this->payload(), 100 )['status'] );
		$this->assertSame( 'disabled', BeaconEndpoint::process( $this->payload(), 100 )['body']['error'] );
		$this->enable( true, 0 );
		$this->assertSame( 403, BeaconEndpoint::process( $this->payload(), 100 )['status'] );
	}

	public function test_the_default_settings_are_opt_in_with_a_five_percent_sample() {
		$defaults = \DSolutions\CookieConsent\Settings\SettingsSchema::defaults()['scanner'];
		$this->assertFalse( $defaults['beacon_enabled'] );
		$this->assertSame( 5, $defaults['beacon_sample'] );
		$this->assertSame( 'off', $defaults['auto_scan'] );
		$this->assertSame( 50, $defaults['max_urls'] );
	}

	public function test_a_report_is_merged_and_leaks_are_alerted() {
		PlatformManager::enable( 'meta-pixel' );
		PlatformManager::enable( 'microsoft-clarity' );
		$res = BeaconEndpoint::process( $this->payload(), 300 );
		$this->assertSame( 200, $res['status'] );

		$fbp = CookieRepository::find_identity( '_fbp', 'cookie', '' );
		$this->assertSame( 'client', $fbp['set_by'] );
		$this->assertSame( 1, $fbp['seen_count'] );
		$this->assertSame( array( '/shop/' ), json_decode( $fbp['seen_on_urls'], true ) );
		$this->assertSame( 'new', CookieRepository::find_identity( 'LittleD_unknown', 'cookie', '' )['status'] );
		$this->assertNotNull( CookieRepository::find_identity( 'LittleD_key', 'localStorage', '' ), 'storage keys are catalogued with their kind' );
		$this->assertContains( 'beacon', ServiceRepository::find_by_slug( 'microsoft-clarity' )['detected_via'] );
		$this->assertNull( ServiceRepository::find_by_slug( 'cdn-littled-example' ), 'unknown hosts create nothing' );
		$this->assertSame( array( 'beacon' ), ServiceRepository::find_by_slug( 'microsoft-clarity' )['detected_via'] );

		$leaks = AlertStore::leaks();
		$this->assertSame( array( '_fbp' ), array_column( $leaks, 'name' ), 'marketing is denied, analytics is allowed, unclassified is not a leak' );
		$this->assertSame( 'marketing', $leaks[0]['category'] );
		$this->assertSame( 1, $leaks[0]['count'] );

		BeaconEndpoint::process( $this->payload(), 300 );
		$this->assertSame( 2, AlertStore::leaks()[0]['count'] );
		$this->assertSame( 2, CookieRepository::find_identity( '_fbp', 'cookie', '' )['seen_count'] );
	}

	public function test_a_vendor_switched_off_inside_an_allowed_category_leaks() {
		PlatformManager::enable( 'microsoft-clarity' );
		PlatformManager::enable( 'google-analytics' );
		BeaconEndpoint::process(
			$this->payload(
				array(
					'c' => array( '_ga', '_clck' ),
					's' => array(
						'c' => array( 'analytics' => 1, 'marketing' => 0 ),
						's' => array( 'microsoft-clarity' => 0 ),
					),
				)
			),
			300
		);
		$this->assertSame( array( '_clck' ), array_column( AlertStore::leaks(), 'name' ) );
	}

	public function test_no_ip_address_is_stored() {
		BeaconEndpoint::process( $this->payload(), 300 );
		$dump = wp_json_encode( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . \DSolutions\CookieConsent\Database\Schema::cookies_table(), ARRAY_A ) ) . wp_json_encode( get_option( AlertStore::OPTION ) );
		$this->assertStringNotContainsString( '203.0.113', $dump );
	}

	public function test_requests_are_refused_for_big_bodies_foreign_origins_and_bad_payloads() {
		$this->assertSame( 413, BeaconEndpoint::process( $this->payload(), 5000 )['status'] );

		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$this->assertSame( 403, BeaconEndpoint::process( $this->payload(), 300 )['status'] );
		unset( $_SERVER['HTTP_ORIGIN'] );
		$this->assertSame( 403, BeaconEndpoint::process( $this->payload(), 300 )['status'], 'no Origin and no Referer' );
		$_SERVER['HTTP_ORIGIN'] = home_url();

		$this->assertSame( 400, BeaconEndpoint::process( 'not json', 10 )['status'] );
		$this->assertSame( 400, BeaconEndpoint::process( $this->payload( array( 's' => null ) ), 300 )['status'] );
		$this->assertNull( CookieRepository::find_identity( '_fbp', 'cookie', '' ) );
	}

	public function test_logged_in_users_are_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$res = BeaconEndpoint::process( $this->payload(), 300 );
		$this->assertSame( 403, $res['status'] );
		$this->assertSame( 'logged_in', $res['body']['error'] );
		$this->assertFalse( has_action( 'wp_ajax_dscc_beacon' ), 'no handler for logged-in requests' );
		$this->assertNotFalse( has_action( 'wp_ajax_nopriv_dscc_beacon' ) );
	}

	public function test_the_rest_mirror_is_registered() {
		do_action( 'rest_api_init' );
		$this->assertArrayHasKey( '/dscc/v1/beacon', rest_get_server()->get_routes() );
	}

	public function test_a_network_is_rate_limited() {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$codes                  = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$codes[] = BeaconEndpoint::process( $this->payload(), 300 )['status'];
		}
		$this->assertSame( array( 200, 200, 200, 429, 429 ), $codes );
	}

	public function test_a_flood_of_made_up_names_creates_a_bounded_number_of_rows() {
		$names = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$names[] = 'LittleD_flood_' . $i;
		}
		$res = BeaconEndpoint::process( $this->payload( array( 'c' => $names ) ), 1000 );
		$this->assertSame( 200, $res['status'] );
		$created = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \DSolutions\CookieConsent\Database\Schema::cookies_table() . " WHERE name LIKE 'LittleD\\_flood\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( BeaconCollector::MAX_NEW_PER_REPORT, $created );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.99';
		BeaconEndpoint::process( $this->payload( array( 'c' => array_map( static function ( $n ) { return $n . 'b'; }, $names ) ) ), 1000 );
		BeaconEndpoint::process( $this->payload( array( 'c' => array_map( static function ( $n ) { return $n . 'c'; }, $names ) ) ), 1000 );
		BeaconEndpoint::process( $this->payload( array( 'c' => array_map( static function ( $n ) { return $n . 'd'; }, $names ) ) ), 1000 );
		$total = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \DSolutions\CookieConsent\Database\Schema::cookies_table() . " WHERE name LIKE 'LittleD\\_flood\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertLessThanOrEqual( BeaconCollector::MAX_NEW_PER_HOUR, $total, 'all reports together stay within the hourly budget' );
	}

	public function test_forged_reports_cannot_create_platforms_or_feed_the_block_list() {
		$before = count( ServiceRepository::all() );
		$forged = $this->payload( array( 'c' => array( '_fbp', '_ga', 'LittleD_forged', 'pll_language' ), 'h' => array( 'www.youtube.com', 'www.clarity.ms', 'connect.facebook.net' ) ) );
		$this->assertSame( 200, BeaconEndpoint::process( $forged, 300 )['status'] );

		$slugs = wp_list_pluck( ServiceRepository::all(), 'slug' );
		$this->assertNotContains( 'youtube', $slugs, 'a forged host must not create a platform (it would block every embed)' );
		$this->assertNotContains( 'microsoft-clarity', $slugs );
		$this->assertNotContains( 'meta-pixel', $slugs, 'a forged known cookie name must not create its platform either' );
		$this->assertSame( array( 'site-unclassified' ), array_values( array_diff( wp_list_pluck( ServiceRepository::all(), 'slug' ), array( 'site-necessary' ) ) ), 'only the hidden holders exist' );
		$this->assertGreaterThan( $before, count( ServiceRepository::all() ) );

		$fbp = CookieRepository::find_identity( '_fbp', 'cookie', '' );
		$this->assertSame( 'new', $fbp['status'] );
		$this->assertNotSame( '', $fbp['purpose'], 'the known purpose is kept as a hint for the admin' );
		$this->assertSame( 'unclassified', \DSolutions\CookieConsent\Catalog\CookieActions::category_of( $fbp ) );

		\DSolutions\CookieConsent\Blocker\ScriptRules::flush();
		$this->assertSame( array(), \DSolutions\CookieConsent\Blocker\ScriptRules::active()['cookies'], 'visitor-reported names never reach the block list' );
		$this->assertSame( array(), AlertStore::leaks(), 'unclassified is not a leak' );
	}

	public function test_a_wildcard_cookie_name_cannot_switch_the_blocker_to_everything() {
		foreach ( array( '*', 'a*', '?', 'a=b' ) as $i => $name ) {
			$_SERVER['REMOTE_ADDR'] = '192.0.' . ( 2 + $i ) . '.9'; // One report per network per request: the rate limit is not under test.
			$res = BeaconEndpoint::process( $this->payload( array( 'c' => array( $name, 'LittleD_ok' ) ) ), 300 );
			$this->assertSame( 200, $res['status'] );
			$this->assertNull( CookieRepository::find_identity( $name, 'cookie', '' ), $name );
		}
		$patterns = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \DSolutions\CookieConsent\Database\Schema::cookies_table() . ' WHERE name_is_pattern = 1' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 0, $patterns );
		// The same rule holds for every source, not only the beacon.
		$res = FindingStore::merge_cookies( array( array( 'name' => '*', 'set_by' => 'server' ), array( 'name' => 'a*', 'set_by' => 'server' ), array( 'name' => '?', 'set_by' => 'server' ), array( 'name' => 'a=b', 'set_by' => 'server' ) ), array( 'via' => 'server' ) );
		$this->assertSame( array(), $res );
	}

	public function test_wordpress_admin_cookies_are_never_recorded() {
		BeaconEndpoint::process( $this->payload( array( 'c' => array( 'wordpress_logged_in_x', 'wp-settings-1', 'wp-settings-time-1', 'wordpress_test_cookie', 'wp_lang', '_ga' ) ) ), 300 );
		foreach ( array( 'wordpress_logged_in_x', 'wp-settings-1', 'wp-settings-time-1', 'wordpress_test_cookie', 'wp_lang' ) as $name ) {
			$this->assertNull( CookieRepository::find_identity( $name, 'cookie', '' ), $name );
		}
		$this->assertNotNull( CookieRepository::find_identity( '_ga', 'cookie', '' ) );
	}

	public function test_alerts_expire_and_are_capped() {
		AlertStore::record_leaks( array( array( 'name' => 'old', 'storage' => 'cookie', 'category' => 'marketing' ) ), 'beacon', time() - 40 * DAY_IN_SECONDS );
		$this->assertSame( array(), AlertStore::leaks(), 'older than 30 days' );
		$many = array();
		for ( $i = 0; $i < 70; $i++ ) {
			$many[] = array( 'name' => 'LittleD_l' . $i, 'storage' => 'cookie', 'category' => 'marketing' );
		}
		AlertStore::record_leaks( $many, 'deep' );
		$this->assertCount( AlertStore::MAX_LEAKS, AlertStore::leaks() );
		AlertStore::clear();
		$this->assertSame( array(), AlertStore::leaks() );
	}

	public function test_client_data_carries_the_beacon_rate_only_when_enabled() {
		$vm = ViewModel::build();
		$this->assertSame( array( 'rate' => 5 ), $vm['data']['beacon'] );
		$this->enable( false );
		$vm = ViewModel::build();
		$this->assertNull( $vm['data']['beacon'] );
	}

	public function test_beacon_adds_no_database_query_and_no_per_visitor_difference_to_a_page_view() {
		global $wpdb;
		PlatformManager::enable( 'google-analytics' );
		FrontendCatalogCache::rebuild();
		$render = static function () {
			ob_start();
			Renderer::print_markup( ViewModel::build() );
			return ob_get_clean();
		};
		$render();
		$before = $wpdb->num_queries;
		$html   = $render();
		$this->assertSame( 0, $wpdb->num_queries - $before, 'beacon configuration comes from the autoloaded settings option' );
		$this->assertStringContainsString( '"beacon":{"rate":5}', $html );
		$this->assertSame( $html, $render(), 'identical for every visitor' );
	}
}
