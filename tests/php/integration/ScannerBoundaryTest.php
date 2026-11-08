<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Admin\ScanPanel;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Frontend\Renderer;
use DSolutions\CookieConsent\Frontend\ViewModel;
use DSolutions\CookieConsent\Scanner\BeaconEndpoint;
use DSolutions\CookieConsent\Scanner\BeaconValidator;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Scanner\GtmContainerReader;
use DSolutions\CookieConsent\Scanner\ScanRequest;
use DSolutions\CookieConsent\Scanner\ScanRunner;
use DSolutions\CookieConsent\Scanner\ScanRunRepository;
use DSolutions\CookieConsent\Scanner\ScanScheduler;
use DSolutions\CookieConsent\Scanner\UrlCollector;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Boundary checks for the scanner, the beacon and the scan endpoints: who may start a scan, which addresses the scan
 * may touch, the size limit of a beacon, the cron teardown, and that consent state does not change the rendered banner.
 */
class Dscc_Scanner_Boundary_Test extends WP_UnitTestCase {

	/** Requests the mocked HTTP layer saw. */
	private $requests = array();

	/** Response for the Tag Manager address. */
	private $gtm = array(
		'code' => 200,
		'body' => 'var a=["G-LITTLED000"];',
	);

	public function set_up() {
		parent::set_up();
		Activator::install();
		ScanPanel::register();
		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		unset( $_GET[ ScanRequest::PARAM ], $_COOKIE['dscc_consent'], $_REQUEST['nonce'], $_POST['nonce'], $_SERVER['HTTP_ORIGIN'] );
		parent::tear_down();
	}

	/** Records every outgoing request; only the Tag Manager address is answered (the other tests do not fetch the site). */
	public function mock_http( $pre, $args, $url ) {
		$this->requests[] = $url;
		return array(
			'headers'  => array( 'content-type' => 'application/javascript' ),
			'body'     => $this->gtm['body'],
			'response' => array(
				'code'    => $this->gtm['code'],
				'message' => 'x',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Run a handler that ends with wp_die()/wp_send_json() and return what it printed.
	 *
	 * @param callable $fn Handler.
	 * @return string
	 */
	private function capture_ajax( $fn ) {
		$stop = static function () {
			throw new Dscc_Boundary_Die();
		};
		$hook = static function () use ( $stop ) {
			return $stop;
		};
		// wp_send_json() calls die() outside AJAX requests; pretend to be one so it goes through wp_die().
		$ajax = static function () {
			return true;
		};
		add_filter( 'wp_die_ajax_handler', $hook );
		add_filter( 'wp_die_handler', $hook );
		add_filter( 'wp_doing_ajax', $ajax );
		ob_start();
		try {
			call_user_func( $fn );
		} catch ( Dscc_Boundary_Die $e ) {
			// Expected: the handler ends here.
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_die_ajax_handler', $hook );
		remove_filter( 'wp_die_handler', $hook );
		remove_filter( 'wp_doing_ajax', $ajax );
		return $out;
	}

	/* ------------------------------------------------------------ who may scan */

	public function test_scan_endpoints_are_for_logged_in_admins_only() {
		$this->assertFalse( has_action( 'wp_ajax_nopriv_dscc_scan_start' ) );
		$this->assertFalse( has_action( 'wp_ajax_nopriv_dscc_scan_status' ) );
		$this->assertFalse( has_action( 'admin_post_nopriv_dscc_alerts_clear' ) );
		$this->assertNotFalse( has_action( 'wp_ajax_dscc_scan_start' ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$_REQUEST['nonce'] = $_POST['nonce'] = wp_create_nonce( ScanPanel::NONCE );
		$out               = $this->capture_ajax( array( ScanPanel::class, 'ajax_start' ) );
		$this->assertStringContainsString( '"success":false', $out, 'a valid nonce is not enough without manage_options' );
		$this->assertNull( ScanRunRepository::latest( 'server', 'running' ) );

		$_REQUEST['nonce'] = $_POST['nonce'] = 'LittleD-forged';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$out = $this->capture_ajax( array( ScanPanel::class, 'ajax_start' ) );
		$this->assertStringNotContainsString( '"success":true', $out, 'a forged nonce starts nothing' );
		$this->assertNull( ScanRunRepository::latest( 'server', 'running' ) );
	}

	/* ------------------------------------------------------------ scan token */

	public function test_forged_expired_or_malformed_scan_tokens_mark_nothing() {
		$_GET[ ScanRequest::PARAM ] = str_repeat( 'a', 24 );
		$this->assertFalse( ScanRequest::is_scan_request() );

		$_GET[ ScanRequest::PARAM ] = ScanRequest::token( 2 );
		$this->assertFalse( ScanRequest::is_scan_request(), 'two windows old is expired' );

		$_GET[ ScanRequest::PARAM ] = ScanRequest::token( 0 ) . 'x';
		$this->assertFalse( ScanRequest::is_scan_request(), 'wrong length' );

		$_GET[ ScanRequest::PARAM ] = array( 'LittleD' );
		$this->assertFalse( ScanRequest::is_scan_request(), 'array value' );

		$_GET[ ScanRequest::PARAM ] = ScanRequest::token( 1 );
		$this->assertTrue( ScanRequest::is_scan_request(), 'the previous window is still accepted' );

		// A visitor with a bad token gets no output buffer, so no held-cookie header and no cache bypass.
		$_GET[ ScanRequest::PARAM ] = str_repeat( 'b', 24 );
		$level                      = ob_get_level();
		ScanRequest::maybe_start();
		$this->assertSame( $level, ob_get_level() );
	}

	/* ------------------------------------------------------------ which addresses the scan may touch */

	public function test_own_site_check_refuses_userinfo_lookalikes_and_other_schemes() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$bad  = array(
			'https://' . $host . '@evil.example/x',
			'https://' . $host . ':@evil.example/x',
			'https://' . $host . '.evil.example/x',
			'https://' . $host . '%40evil.example/x',
			'https://evil.example/?' . $host,
			'https://evil.example#' . $host,
			'//' . $host . '/x',
			'/relative/path',
			'javascript:alert(1)',
			'ftp://' . $host . '/x',
			'file:///etc/passwd',
		);
		foreach ( $bad as $url ) {
			$this->assertFalse( UrlCollector::is_own_url( $url ), $url );
		}
		$this->assertTrue( UrlCollector::is_own_url( home_url( '/x' ) ) );
		$port = wp_parse_url( home_url(), PHP_URL_PORT );
		$this->assertTrue( UrlCollector::is_own_url( strtoupper( wp_parse_url( home_url(), PHP_URL_SCHEME ) ) . '://' . strtoupper( $host ) . ( $port ? ':' . $port : '' ) . '/x' ), 'scheme and host are case-insensitive; the port is pinned to the home port' );
	}

	public function test_url_collector_drops_foreign_urls_from_the_filter() {
		add_filter(
			'dscc_scan_urls',
			static function () {
				return array( 'https://evil.example/', 'javascript:alert(1)', home_url( '/littled-page/' ) );
			}
		);
		$urls = UrlCollector::collect( 50 );
		remove_all_filters( 'dscc_scan_urls' );
		$this->assertSame( array( home_url( '/littled-page/' ) ), $urls );
	}

	public function test_tag_manager_reader_fetches_one_address_and_nothing_else() {
		foreach ( array( 'GTM-ABCD/../x', 'gtm-abcd', 'GTM-ABC', 'GTM-ABCD&x=1', 'https://evil.example/', 'GTM-ABCDEFGHIJKLMNOP' ) as $bad ) {
			$this->assertSame( 'bad_id', GtmContainerReader::read( $bad )['error'], $bad );
		}
		$this->assertSame( array(), $this->requests, 'a bad id never reaches the network' );

		$this->gtm = array(
			'code' => 302,
			'body' => '',
		);
		$read      = GtmContainerReader::read( 'GTM-ABCDEF' );
		$this->assertFalse( $read['ok'] );
		$this->assertSame( 'http_302', $read['error'], 'a redirect from Tag Manager is not followed' );
		$this->assertSame( array( 'https://www.googletagmanager.com/gtm.js?id=GTM-ABCDEF' ), $this->requests );
	}

	/* ------------------------------------------------------------ beacon size */

	public function test_beacon_body_limit_is_exactly_4096_bytes() {
		$base  = array(
			'c' => array( '_ga' ),
			'l' => array(),
			'x' => array(),
			'h' => array(),
			's' => array(
				'c' => array(
					'analytics' => 1,
					'marketing' => 0,
				),
				's' => array(),
			),
			'u' => '/',
		);
		$pad   = 4096 - strlen( (string) wp_json_encode( $base ) );
		$exact = (string) wp_json_encode( array_merge( $base, array( 'u' => '/' . str_repeat( 'a', $pad ) ) ) );
		$this->assertSame( 4096, strlen( $exact ) );
		$this->assertTrue( BeaconValidator::validate( $exact )['ok'], 'exactly 4096 bytes is accepted' );

		$over = (string) wp_json_encode( array_merge( $base, array( 'u' => '/' . str_repeat( 'a', $pad + 1 ) ) ) );
		$this->assertSame( 4097, strlen( $over ) );
		$this->assertSame( 'too_large', BeaconValidator::validate( $over )['error'] );

		Settings::save_group(
			'scanner',
			array_merge(
				Settings::group( 'scanner' ),
				array(
					'beacon_enabled' => '1',
					'beacon_sample'  => 5,
				)
			)
		);
		$_SERVER['HTTP_ORIGIN'] = home_url();
		$res                    = BeaconEndpoint::process( $exact, 4097 );
		$this->assertSame( 413, $res['status'], 'a declared length over the limit is refused before parsing' );
		$this->assertSame( 0, (int) get_transient( 'dscc_beacon_new' ) );
	}

	/* ------------------------------------------------------------ cron teardown */

	public function test_deactivation_clears_the_weekly_event_and_pending_scan_ticks() {
		Settings::save_group(
			'scanner',
			array_merge(
				Settings::group( 'scanner' ),
				array( 'auto_scan' => 'weekly' )
			)
		);
		ScanScheduler::sync();
		$start = ScanRunner::start( 'manual' );
		$this->assertIsArray( $start );
		$this->assertNotFalse( wp_next_scheduled( 'dscc_scan_weekly' ) );
		$this->assertNotFalse( wp_next_scheduled( 'dscc_scan_tick', array( (int) $start['id'] ) ) );

		Activator::deactivate();
		$this->assertFalse( wp_next_scheduled( 'dscc_scan_weekly' ) );
		$this->assertFalse( wp_next_scheduled( 'dscc_scan_tick', array( (int) $start['id'] ) ) );
	}

	/* ------------------------------------------------------------ admin decisions vs re-scan */

	public function test_admin_reclassification_survives_a_rescan_and_only_statistics_move() {
		$service = ServiceRepository::upsert(
			array(
				'slug'         => 'littled-vendor',
				'name'         => 'LittleD vendor',
				'category_key' => 'marketing',
				'status'       => 'confirmed',
				'source'       => 'manual',
			)
		);
		CookieRepository::upsert(
			array(
				'name'       => 'LittleD_admin',
				'storage'    => 'cookie',
				'service_id' => $service,
				'status'     => 'confirmed',
				'source'     => 'manual',
			)
		);
		$before = CookieRepository::find_identity( 'LittleD_admin', 'cookie', '' );

		FindingStore::merge_cookies(
			array(
				array(
					'name'    => 'LittleD_admin',
					'set_by'  => 'server',
					'url'     => '/shop/',
					'max_age' => 60,
				),
			),
			array( 'via' => 'server' )
		);

		$after = CookieRepository::find_identity( 'LittleD_admin', 'cookie', '' );
		$this->assertSame( (int) $before['service_id'], (int) $after['service_id'], 'the admin\'s platform is kept' );
		$this->assertSame( 'confirmed', $after['status'] );
		$this->assertSame( (int) $before['seen_count'] + 1, (int) $after['seen_count'], 'only the statistics move' );
		$this->assertSame( 'server', $after['set_by'] );
		$this->assertSame( $before['duration_seconds'], $after['duration_seconds'], 'the duration of an admin row is not rewritten by a scan' );
	}

	/* ------------------------------------------------------------ consent state and the rendered banner */

	public function test_rendered_banner_is_the_same_for_visitors_with_and_without_a_consent_record() {
		Settings::save_group(
			'scanner',
			array_merge(
				Settings::group( 'scanner' ),
				array(
					'beacon_enabled' => '1',
					'beacon_sample'  => 5,
				)
			)
		);
		$render = static function () {
			ob_start();
			Renderer::print_markup( ViewModel::build() );
			return (string) ob_get_clean();
		};

		unset( $_COOKIE['dscc_consent'] );
		$none = $render();

		$version                     = (string) Settings::get( 'advanced', 'policy_version', '' );
		$_COOKIE['dscc_consent']     = wp_json_encode(
			array(
				'v'   => $version,
				'id'  => 'LittleD-0001-aaaa',
				't'   => time(),
				'c'   => array(
					'analytics' => 1,
					'marketing' => 1,
				),
				's'   => array(),
				'src' => 'banner',
			)
		);
		$accepted                    = $render();
		$_COOKIE['dscc_consent']     = wp_json_encode(
			array(
				'v'   => $version,
				'id'  => 'LittleD-0002-bbbb',
				't'   => time(),
				'c'   => array(
					'analytics' => 0,
					'marketing' => 0,
				),
				's'   => array(),
				'src' => 'banner',
			)
		);
		$refused                     = $render();
		unset( $_COOKIE['dscc_consent'] );

		$this->assertSame( $none, $accepted, 'banner markup does not depend on the visitor\'s choice' );
		$this->assertSame( $none, $refused );
		$this->assertStringContainsString( '"beacon":{"rate":5}', $none, 'the beacon setting is the same for everyone' );
	}
}

/** Thrown by the wp_die() replacement in capture_ajax(). */
class Dscc_Boundary_Die extends Exception {
}
