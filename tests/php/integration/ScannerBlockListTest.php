<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Blocker\CookieSafelist;
use DSolutions\CookieConsent\Blocker\ScriptRules;
use DSolutions\CookieConsent\Catalog\CookieActions;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Scanner\ScanNotifier;
use DSolutions\CookieConsent\Scanner\ScanRunner;
use DSolutions\CookieConsent\Scanner\UrlCollector;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * What the scanner puts on the block list: only literal cookies the server sent, behind the confirmed ones, capped, switchable
 * to report-only, with the admin's own keep list, per-source caps and the own-host rules.
 */
class Dscc_Scanner_Block_List_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
		FindingStore::reset();
		FindingStore::clear_dropped();
		FrontendCatalogCache::rebuild();
		ScriptRules::flush();
		$this->blocking( array( 'unclassified_cookie_mode' => 'block', 'keep_cookies' => array() ) );
		delete_option( 'dscc_scan_mail_at' );
		reset_phpmailer_instance();
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.test';
			}
		);
	}

	public function tear_down() {
		remove_all_filters( 'dscc_cookie_safelist' );
		remove_all_filters( 'pre_option_home' );
		remove_all_filters( 'pre_option_siteurl' );
		ScriptRules::flush();
		parent::tear_down();
	}

	private function blocking( array $values ) {
		Settings::save_group( 'blocking', array_merge( Settings::group( 'blocking' ), $values ) );
		ScriptRules::flush();
	}

	private function server( array $names ) {
		return FindingStore::merge_cookies(
			array_map(
				static function ( $name ) {
					return array( 'name' => $name, 'set_by' => 'server' );
				},
				$names
			),
			array( 'via' => 'server' )
		);
	}

	private function blocked_names() {
		ScriptRules::flush();
		return array_column( ScriptRules::active()['cookies'], 0 );
	}

	public function test_only_server_sent_literal_cookies_are_blocked_and_the_snapshot_is_current() {
		$this->server( array( 'LittleD_server' ) );
		FindingStore::merge_cookies( array( array( 'name' => 'LittleD_client', 'set_by' => 'client' ) ), array( 'via' => 'beacon' ) );
		FindingStore::merge_cookies( array( array( 'name' => 'LittleD_local', 'storage' => 'localStorage', 'set_by' => 'server' ) ), array( 'via' => 'server' ) );
		$this->assertSame( array( 'LittleD_server' ), $this->blocked_names() );

		// A pattern row (however it got there) is never put on the list.
		CookieRepository::upsert( array( 'name' => 'LittleD_p*', 'service_id' => \DSolutions\CookieConsent\Scanner\KnownServiceRegistry::holder( 'unclassified' ), 'status' => 'new', 'set_by' => 'server', 'source' => 'scan' ) );
		$this->assertSame( array( 'LittleD_server' ), $this->blocked_names() );
		$this->assertSame( ScriptRules::VERSION, ScriptRules::snapshot()['v'] );
	}

	public function test_a_flood_of_new_cookies_never_pushes_out_confirmed_ones_and_the_cap_is_reported() {
		PlatformManager::enable( 'google-analytics' );
		$names = array();
		for ( $i = 0; $i < 130; $i++ ) {
			$names[] = 'LittleD_n' . $i;
		}
		$this->server( $names );
		$list = $this->blocked_names();
		$this->assertContains( '_ga', $list, 'confirmed cookies come first' );
		$new = array_filter(
			$list,
			static function ( $n ) {
				return 0 === strpos( $n, 'LittleD_n' );
			}
		);
		$this->assertCount( ScriptRules::MAX_NEW_COOKIES, $new );
		$this->assertTrue( ScriptRules::new_cookies_capped() );
		$this->assertLessThanOrEqual( ScriptRules::MAX_COOKIES, count( $list ) );
		$report = \DSolutions\CookieConsent\Admin\ComplianceReport::build();
		$this->assertTrue( $report['new_capped'] );
	}

	public function test_report_mode_lists_but_does_not_strip_and_applies_without_a_rebuild() {
		$this->server( array( 'LittleD_server' ) );
		PlatformManager::enable( 'google-analytics' );
		$this->assertContains( 'LittleD_server', $this->blocked_names() );
		$built = get_option( ScriptRules::OPTION );
		$this->blocking( array( 'unclassified_cookie_mode' => 'report' ) );
		$list = $this->blocked_names();
		$this->assertNotContains( 'LittleD_server', $list );
		$this->assertContains( '_ga', $list, 'confirmed platforms are still blocked' );
		$this->assertSame( $built, get_option( ScriptRules::OPTION ), 'the mode is read at run time: no rebuild' );
		$this->assertSame( array(), array_filter( \DSolutions\CookieConsent\Blocker\ServerCookieGuard::split( array( 'Set-Cookie: LittleD_server=1' ), ScriptRules::active()['cookies'], null )['names'] ) );
		$this->assertSame( 1, \DSolutions\CookieConsent\Catalog\CookieQuery::unclassified_open()['total'], 'still listed for the admin' );
	}

	public function test_the_admin_keep_list_and_the_filter_extend_the_safelist() {
		$this->assertContains( '_lscache_vary', CookieSafelist::patterns() );
		$this->assertContains( 'wfwaf-*', CookieSafelist::patterns() );
		$this->blocking( array( 'keep_cookies' => "shop_*\nmy_session\n*\nab\n<b>\nbad name" ) );
		$keep = Settings::get( 'blocking', 'keep_cookies' );
		$this->assertSame( array( 'shop_*', 'my_session' ), $keep, 'a bare star and junk are refused' );
		add_filter(
			'dscc_cookie_safelist',
			static function () {
				return array( 'LittleD_filtered', '*', 5 );
			}
		);
		$patterns = CookieSafelist::patterns();
		$this->assertContains( 'LittleD_filtered', $patterns );
		$this->assertContains( 'shop_*', $patterns );
		$this->assertNotContains( '*', $patterns );

		$res = $this->server( array( 'shop_cart', 'my_session', 'LittleD_filtered', 'wfwaf-authcookie-1', '_lscache_vary', 'LittleD_other' ) );
		$by  = array_column( $res, null, 'name' );
		foreach ( array( 'shop_cart', 'my_session', 'LittleD_filtered', 'wfwaf-authcookie-1', '_lscache_vary' ) as $name ) {
			$this->assertSame( 'necessary', $by[ $name ]['category'], $name );
		}
		$this->assertSame( array( 'LittleD_other' ), $this->blocked_names() );
		// The browser gets the same list.
		$data = \DSolutions\CookieConsent\Frontend\ClientData::build( \DSolutions\CookieConsent\Frontend\ViewModel::build() );
		$this->assertContains( 'shop_*', $data['block']['keep'] );
		$this->assertContains( 'wfwaf-*', $data['block']['keep'] );
	}

	public function test_mark_necessary_files_an_unclassified_cookie_under_necessary_and_stops_blocking_it() {
		$res = $this->server( array( 'LittleD_server' ) );
		$id  = $res[0]['id'];
		$this->assertContains( 'LittleD_server', $this->blocked_names() );
		CookieActions::move_category( array( $id ), 'necessary' );
		CookieActions::confirm( array( $id ) );
		$this->assertNotContains( 'LittleD_server', $this->blocked_names() );
		$this->assertSame( 'confirmed', CookieRepository::find( $id )['status'] );
	}

	public function test_beacon_noise_has_its_own_cap_and_never_hides_server_findings() {
		$names = array();
		for ( $i = 0; $i < FindingStore::MAX_NEW + 20; $i++ ) {
			$names[] = array( 'name' => 'LittleD_b' . $i, 'set_by' => 'client' );
		}
		foreach ( array_chunk( $names, 100 ) as $chunk ) {
			FindingStore::merge_cookies( $chunk, array( 'via' => 'beacon' ) );
		}
		$new = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \DSolutions\CookieConsent\Database\Schema::cookies_table() . " WHERE status = 'new'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( FindingStore::MAX_NEW, $new );
		$this->assertSame( 20, FindingStore::dropped_count() );

		$res = $this->server( array( 'LittleD_real' ) );
		$this->assertTrue( $res[0]['created'], 'the server scan is exempt from the cap' );
		FindingStore::reset();
		$res = FindingStore::merge_cookies( array( array( 'name' => 'LittleD_more', 'set_by' => 'client' ) ), array( 'via' => 'deep' ) );
		$this->assertSame( 'capped', $res[0]['match'], 'other non-server sources share the capped pool' );
		FindingStore::clear_dropped();
		$this->assertSame( 0, FindingStore::dropped_count() );
	}

	/** Point home and site address somewhere else for the rest of the test (the filters go with the test). */
	private function set_address( $url ) {
		remove_all_filters( 'pre_option_home' );
		remove_all_filters( 'pre_option_siteurl' );
		add_filter(
			'pre_option_home',
			static function () use ( $url ) {
				return $url;
			}
		);
		add_filter(
			'pre_option_siteurl',
			static function () use ( $url ) {
				return $url;
			}
		);
	}

	public function test_own_hosts_include_the_www_variant_and_the_port_is_pinned() {
		$this->set_address( 'http://littled.example' );
		$this->assertContains( 'www.littled.example', UrlCollector::own_hosts() );
		$this->assertTrue( UrlCollector::is_own_url( 'http://www.littled.example/x' ) );
		$this->assertTrue( UrlCollector::is_own_url( 'https://littled.example/x' ), 'default ports may switch between http and https' );
		$this->assertFalse( UrlCollector::is_own_url( 'http://littled.example:8081/x' ) );
		$this->assertFalse( UrlCollector::is_own_url( 'http://evil.littled.example/x' ) );
		$this->assertFalse( UrlCollector::is_own_url( 'http://littled.example.evil.test/x' ) );
		$this->assertSame( array( 80, 443 ), UrlCollector::own_ports() );

		$this->set_address( 'https://www.littled.example:8443' );
		$this->assertContains( 'littled.example', UrlCollector::own_hosts(), 'the bare host of a www home' );
		$this->assertSame( array( 8443 ), UrlCollector::own_ports() );
		$this->assertTrue( UrlCollector::is_own_url( 'https://littled.example:8443/x' ) );
		$this->assertFalse( UrlCollector::is_own_url( 'https://littled.example/x' ), 'another port is another service' );
	}

	public function test_the_email_lists_the_platforms_the_scan_added() {
		Settings::save_group( 'scanner', array( 'notify_enabled' => '1', 'notify_email' => 'littled@example.test' ) );
		$this->assertTrue( ScanNotifier::maybe_send( array( 'new_cookies' => 0, 'new_platforms' => array( 'Meta Pixel', 'Microsoft Clarity' ) ), 1000 ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertStringContainsString( 'Meta Pixel, Microsoft Clarity', $mail->body );
	}

	public function test_a_run_reports_the_platforms_it_added_and_queues_the_next_tick_before_working() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array( 'content-type' => 'text/html' ),
					'body'     => '<script src="https://www.clarity.ms/tag/littled000"></script>',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
		$start = ScanRunner::start( 'manual' );
		wp_clear_scheduled_hook( 'dscc_scan_tick', array( $start['id'] ) );
		ScanRunner::tick( $start['id'] );
		$st = \DSolutions\CookieConsent\Scanner\ScanRunRepository::find( $start['id'] );
		$this->assertContains( 'Microsoft Clarity', $st['summary']['new_platforms'] );
	}
}
