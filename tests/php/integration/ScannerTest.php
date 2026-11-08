<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Blocker\ScriptRules;
use DSolutions\CookieConsent\Blocker\ServerCookieGuard;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Database\Schema;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Scanner\GtmContainerReader;
use DSolutions\CookieConsent\Scanner\ScanNotifier;
use DSolutions\CookieConsent\Scanner\ScanRequest;
use DSolutions\CookieConsent\Scanner\ScanRunner;
use DSolutions\CookieConsent\Scanner\ScanRunRepository;
use DSolutions\CookieConsent\Scanner\ScanScheduler;
use DSolutions\CookieConsent\Scanner\ServerScanner;
use DSolutions\CookieConsent\Scanner\UrlCollector;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Server scan end to end against a mocked HTTP layer (pre_http_request): URL collection, cookie recording, classification,
 * platform detection in HTML and in a Tag Manager container, own-host-only requests, run lifecycle, email throttling.
 */
class Dscc_Scanner_Test extends WP_UnitTestCase {

	/** Requests the mocked HTTP layer saw. */
	private $requests = array();

	/** Responses by path; a callable receives the request URL. */
	private $pages = array();

	public function set_up() {
		parent::set_up();
		Activator::install();
		FindingStore::reset();
		FrontendCatalogCache::rebuild();
		ScriptRules::flush();
		delete_option( 'dscc_scan_mail_at' );
		delete_option( 'dscc_scan_lock' );
		$this->requests = array();
		$this->pages    = array();
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
		reset_phpmailer_instance();
		// The test host is "localhost": PHPMailer refuses wordpress@localhost as a sender.
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.test';
			}
		);
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		ScriptRules::flush();
		reset_phpmailer_instance();
		parent::tear_down();
	}

	/** HTTP mock: pages by path on the site, the container for GTM, anything else is a recorded surprise. */
	public function mock_http( $pre, $args, $url ) {
		$this->requests[] = $url;
		$host             = wp_parse_url( $url, PHP_URL_HOST );
		if ( 'www.googletagmanager.com' === $host ) {
			return $this->response( 200, '(function(){var a=["G-LITTLED000","AW-000LITTLED","https:\/\/static.hotjar.com\/c\/hotjar-1.js"];})()' );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( isset( $this->pages[ $path ] ) ) {
			$page = $this->pages[ $path ];
			return is_callable( $page ) ? call_user_func( $page, $url ) : $page;
		}
		return $this->response( 200, '<html><body>LittleD page</body></html>' );
	}

	/** A mocked wp_remote_get() result. */
	private function response( $code, $body, array $headers = array() ) {
		return array(
			'headers'  => array_merge( array( 'content-type' => 'text/html; charset=UTF-8' ), $headers ),
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => 'x',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function home_html() {
		return '<html><head>'
			. '<script async src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>'
			. "<script>fbq('init','000LittleD');</script><script src=\"https://connect.facebook.net/en_US/fbevents.js\"></script>"
			. '<script src="https://www.clarity.ms/tag/littled000"></script>'
			. "<script>(function(w,d,s,l,i){j.src='https://www.googletagmanager.com/gtm.js?id='+i;})(window,document,'script','dataLayer','GTM-LITTLED');</script>"
			. '<script src="https://cdn.littled.example/unknown.js"></script>'
			. '</head><body></body></html>';
	}

	private function run_to_end( $id ) {
		for ( $i = 0; $i < 30; $i++ ) {
			$st = ScanRunner::tick( $id );
			if ( $st['finished'] ) {
				return $st;
			}
		}
		$this->fail( 'scan did not finish' );
	}

	/* ------------------------------------------------------------ FindingStore */

	public function test_findings_are_classified_by_the_rules_of_the_spec() {
		$res = FindingStore::merge_cookies(
			array(
				array( 'name' => '_fbp', 'set_by' => 'server', 'max_age' => 7776000, 'has_lifetime' => true, 'url' => '/' ),
				array( 'name' => 'wp-wpml_current_language', 'set_by' => 'server', 'has_lifetime' => true, 'url' => '/' ),
				array( 'name' => 'LittleD_unknown', 'set_by' => 'server', 'max_age' => 86400, 'has_lifetime' => true, 'url' => '/' ),
				array( 'name' => 'wc_facebook_signals_state', 'set_by' => 'client' ),
				array( 'name' => 'wordpress_littled_x', 'set_by' => 'client' ),
				array( 'name' => 'comment_author_littled', 'set_by' => 'client' ),
				array( 'name' => 'PHPSESSID', 'set_by' => 'server' ),
				array( 'name' => 'woocommerce_items_in_cart', 'set_by' => 'server' ),
			),
			array(
				'source' => 'scan',
				'via'    => 'server',
			)
		);
		$by = array_column( $res, null, 'name' );

		$this->assertSame( 'marketing', $by['_fbp']['category'] );
		$this->assertSame( 'meta-pixel', $by['_fbp']['service_slug'] );
		$this->assertSame( 'auto', $by['_fbp']['status'] );
		$this->assertSame( 'auto', ServiceRepository::find_by_slug( 'meta-pixel' )['status'] );
		$this->assertSame( array( 'server' ), ServiceRepository::find_by_slug( 'meta-pixel' )['detected_via'] );

		$this->assertSame( 'necessary', $by['wp-wpml_current_language']['category'] );
		$this->assertSame( 'auto', $by['wp-wpml_current_language']['status'] );

		$this->assertSame( 'unclassified', $by['LittleD_unknown']['category'] );
		$this->assertSame( 'new', $by['LittleD_unknown']['status'] );
		$this->assertSame( FindingStore::HOLDER_UNCLASSIFIED, $by['LittleD_unknown']['service_slug'] );
		$row = CookieRepository::find( $by['LittleD_unknown']['id'] );
		$this->assertSame( '1 ngày', $row['duration_label'] );
		$this->assertSame( '1 day', $row['duration_label_en'] );
		$this->assertSame( 'server', $row['set_by'] );
		$this->assertSame( 'scan', $row['source'] );

		// Everything the blocker's safelist protects is necessary, never new and never unclassified.
		foreach ( array( 'wc_facebook_signals_state', 'wordpress_littled_x', 'comment_author_littled', 'PHPSESSID', 'woocommerce_items_in_cart' ) as $name ) {
			$this->assertSame( 'necessary', $by[ $name ]['category'], $name );
			$this->assertNotSame( 'new', $by[ $name ]['status'], $name );
		}
		$this->assertSame( 'auto', $by['wordpress_littled_x']['status'] );

		// The holders stay hidden from visitors.
		$this->assertSame( 'auto', ServiceRepository::find_by_slug( FindingStore::HOLDER_UNCLASSIFIED )['status'] );
		foreach ( FrontendCatalogCache::services() as $service ) {
			$this->assertNotSame( FindingStore::HOLDER_UNCLASSIFIED, $service['slug'] );
			$this->assertNotSame( FindingStore::HOLDER_NECESSARY, $service['slug'] );
			$this->assertNotSame( 'meta-pixel', $service['slug'], 'auto services are not shown to visitors' );
		}
	}

	public function test_a_second_sighting_updates_statistics_only() {
		FindingStore::merge_cookies( array( array( 'name' => '_fbp', 'set_by' => 'client', 'url' => '/a/?x=1' ) ), array( 'via' => 'server' ) );
		$id    = CookieRepository::find_identity( '_fbp', 'cookie', '' );
		$id    = $id ? $id['id'] : CookieRepository::for_service( ServiceRepository::find_by_slug( 'meta-pixel' )['id'] )[0]['id'];
		$rows  = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Schema::cookies_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$fired = did_action( 'dscc_catalog_changed' );

		$res = FindingStore::merge_cookies( array( array( 'name' => '_fbp', 'set_by' => 'server', 'http_only' => true, 'url' => '/b/' ) ), array( 'via' => 'server' ) );

		$this->assertFalse( $res[0]['created'] );
		$this->assertSame( $fired, did_action( 'dscc_catalog_changed' ), 'statistics must not flush the catalog caches' );
		$this->assertSame( $rows, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Schema::cookies_table() ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = CookieRepository::find( $res[0]['id'] );
		$this->assertSame( 2, $row['seen_count'] );
		$this->assertSame( 'server', $row['set_by'], 'the more certain source wins' );
		$this->assertSame( 1, $row['http_only'] );
		$this->assertSame( array( '/a/', '/b/' ), json_decode( $row['seen_on_urls'], true ), 'paths only, no query string' );
		$this->assertNotNull( $row['first_seen_at'] );
		$this->assertNotNull( $row['last_seen_at'] );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_decisions_of_the_admin_are_never_overwritten() {
		PlatformManager::enable( 'google-analytics' );
		$ga = ServiceRepository::find_by_slug( 'google-analytics' );
		CookieRepository::upsert( array( 'name' => '_ga', 'purpose' => 'LittleD purpose', 'status' => 'confirmed' ) );
		PlatformManager::enable( 'hotjar' );
		PlatformManager::disable( 'hotjar' );

		FindingStore::merge_cookies(
			array(
				array( 'name' => '_ga', 'set_by' => 'server', 'url' => '/' ),
				array( 'name' => '_hjFirstSeen', 'set_by' => 'client', 'url' => '/' ),
			),
			array( 'via' => 'server' )
		);

		$this->assertSame( 'confirmed', CookieRepository::find_identity( '_ga', 'cookie', '' )['status'] );
		$this->assertSame( 'LittleD purpose', CookieRepository::find_identity( '_ga', 'cookie', '' )['purpose'] );
		$this->assertSame( 'ignored', ServiceRepository::find_by_slug( 'hotjar' )['status'] );
		$hj = CookieRepository::for_service( ServiceRepository::find_by_slug( 'hotjar' )['id'] );
		foreach ( $hj as $cookie ) {
			$this->assertSame( 'ignored', $cookie['status'] );
		}
		$this->assertSame( $ga['status'], ServiceRepository::find_by_slug( 'google-analytics' )['status'] );
	}

	public function test_a_new_cookie_of_an_ignored_platform_is_born_ignored() {
		PlatformManager::enable( 'microsoft-clarity' );
		PlatformManager::disable( 'microsoft-clarity' );
		CookieRepository::delete_many( wp_list_pluck( CookieRepository::for_service( ServiceRepository::find_by_slug( 'microsoft-clarity' )['id'] ), 'id' ) );
		$res = FindingStore::merge_cookies( array( array( 'name' => '_clck', 'set_by' => 'client' ) ), array( 'via' => 'beacon' ) );
		$this->assertSame( 'ignored', $res[0]['status'] );
		$this->assertSame( 'ignored', ServiceRepository::find_by_slug( 'microsoft-clarity' )['status'] );
	}

	public function test_pattern_cookies_share_one_row() {
		$a = FindingStore::merge_cookies( array( array( 'name' => '_ga_ABC123', 'set_by' => 'client' ) ), array( 'via' => 'server' ) );
		$b = FindingStore::merge_cookies( array( array( 'name' => '_ga_XYZ789', 'set_by' => 'client' ) ), array( 'via' => 'server' ) );
		$this->assertTrue( $a[0]['created'] );
		$this->assertFalse( $b[0]['created'] );
		$this->assertSame( $a[0]['id'], $b[0]['id'] );
		$row = CookieRepository::find( $a[0]['id'] );
		$this->assertSame( '_ga_*', $row['name'] );
		$this->assertSame( 2, $row['seen_count'] );
	}

	public function test_scanning_before_and_after_ticking_a_platform_does_not_duplicate_rows() {
		FindingStore::merge_cookies( array( array( 'name' => '_fbp', 'set_by' => 'server' ) ), array( 'via' => 'server' ) );
		PlatformManager::enable( 'meta-pixel' );
		$rows = CookieRepository::for_service( ServiceRepository::find_by_slug( 'meta-pixel' )['id'] );
		$this->assertSame( array( '_fbc', '_fbp', 'fr' ), wp_list_pluck( $rows, 'name' ) );
		foreach ( $rows as $row ) {
			$this->assertSame( 'confirmed', $row['status'] );
		}
		$this->assertSame( 1, CookieRepository::find_identity( '_fbp', 'cookie', '' )['seen_count'] );
	}

	public function test_creation_is_capped_per_call() {
		$obs = array();
		foreach ( array( 'LittleD_a', 'LittleD_b', 'LittleD_c' ) as $name ) {
			$obs[] = array( 'name' => $name, 'set_by' => 'client' );
		}
		$res = FindingStore::merge_cookies( $obs, array( 'via' => 'beacon', 'max_new' => 1 ) );
		$this->assertSame( array( true, false, false ), array_column( $res, 'created' ) );
		$this->assertSame( array( 'unclassified', 'capped', 'capped' ), array_column( $res, 'match' ) );
	}

	public function test_detection_creates_an_auto_platform_and_collects_the_sources() {
		$id = FindingStore::merge_detection( 'microsoft-clarity', 'html' );
		$this->assertGreaterThan( 0, $id );
		FindingStore::merge_detection( 'microsoft-clarity', 'gtm' );
		FindingStore::merge_detection( 'microsoft-clarity', 'gtm' );
		$svc = ServiceRepository::find_by_slug( 'microsoft-clarity' );
		$this->assertSame( 'auto', $svc['status'] );
		$this->assertSame( array( 'html', 'gtm' ), $svc['detected_via'] );
		$this->assertSame( 0, FindingStore::merge_detection( 'not-in-the-database', 'html' ) );

		ServiceRepository::set_status( 'microsoft-clarity', 'ignored' );
		FindingStore::merge_detection( 'microsoft-clarity', 'beacon' );
		$this->assertSame( array( 'html', 'gtm' ), ServiceRepository::find_by_slug( 'microsoft-clarity' )['detected_via'], 'an ignored platform is left alone' );
		$this->assertSame( 'ignored', ServiceRepository::find_by_slug( 'microsoft-clarity' )['status'] );
	}

	public function test_detected_platforms_show_the_badge_data_in_the_picker() {
		FindingStore::merge_detection( 'hotjar', 'gtm' );
		$overview = PlatformManager::overview();
		$this->assertSame( array( 'gtm' ), $overview['hotjar']['detected'] );
		$this->assertFalse( $overview['hotjar']['enabled'] );
	}

	/* ------------------------------------------------------------ blocking interplay */

	public function test_new_unclassified_cookies_are_blocked_like_marketing_but_safelisted_ones_never() {
		Settings::save_group( 'blocking', array_merge( Settings::group( 'blocking' ), array( 'unclassified_cookie_mode' => 'block' ) ) );
		FindingStore::merge_cookies(
			array(
				array( 'name' => 'LittleD_unknown', 'set_by' => 'server' ),
				array( 'name' => 'wp-settings-1', 'set_by' => 'client' ),
				array( 'name' => 'wc_facebook_signals_state', 'set_by' => 'client' ),
			),
			array( 'via' => 'server' )
		);
		ScriptRules::flush();
		$names = array();
		foreach ( ScriptRules::active()['cookies'] as $row ) {
			$names[ $row[0] ] = $row[3];
		}
		$this->assertSame( 'marketing', $names['LittleD_unknown'] );
		$this->assertArrayNotHasKey( 'wp-settings-1', $names );
		$this->assertArrayNotHasKey( 'wc_facebook_signals_state', $names );

		$split = ServerCookieGuard::split( array( 'Set-Cookie: LittleD_unknown=1; path=/', 'Set-Cookie: wp-settings-1=1; path=/' ), ScriptRules::active()['cookies'], null );
		$this->assertSame( array( 'LittleD_unknown' ), $split['names'] );

		Settings::save_group( 'blocking', array_merge( Settings::group( 'blocking' ), array( 'block_unclassified' => false ) ) );
		$this->assertArrayNotHasKey( 'LittleD_unknown', array_column( ScriptRules::active()['cookies'], 3, 0 ) );
	}

	public function test_web_storage_keys_are_catalogued_but_never_purged_and_core_keys_are_necessary() {
		$res = FindingStore::merge_cookies(
			array(
				array( 'name' => 'LittleD_state', 'storage' => 'localStorage', 'set_by' => 'client' ),
				array( 'name' => 'wpEmojiSettingsSupports', 'storage' => 'sessionStorage', 'set_by' => 'client' ),
				array( 'name' => 'dscc_bc', 'storage' => 'sessionStorage', 'set_by' => 'client' ),
			),
			array( 'via' => 'beacon' )
		);
		$by  = array_column( $res, null, 'name' );
		$this->assertSame( 'new', $by['LittleD_state']['status'] );
		$this->assertSame( 'necessary', $by['wpEmojiSettingsSupports']['category'] );
		$this->assertSame( 'necessary', $by['dscc_bc']['category'] );
		ScriptRules::flush();
		$this->assertSame( array(), ScriptRules::active()['cookies'], 'an unclassified storage key is not purged' );
		$this->assertSame( array(), ScriptRules::purge_list() );
	}

	/* ------------------------------------------------------------ URL collector */

	public function test_url_collector_returns_own_site_pages_only_and_respects_the_cap() {
		$ids = self::factory()->post->create_many( 3, array( 'post_title' => 'LittleD post', 'post_status' => 'publish' ) );
		$form = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[contact-form-7 id="1"]' ) );
		add_filter(
			'dscc_scan_urls',
			static function ( $urls ) {
				$urls[] = 'https://evil.example/steal';
				$urls[] = 'http://user:pw@' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/x';
				$urls[] = 'javascript:alert(1)';
				return $urls;
			}
		);
		$urls = UrlCollector::collect( 50 );
		$this->assertSame( home_url( '/' ), $urls[0] );
		$this->assertSame( get_permalink( $form ), $urls[1], 'pages with forms come right after the home page' );
		foreach ( $urls as $url ) {
			$this->assertTrue( UrlCollector::is_own_url( $url ), $url );
		}
		$this->assertContains( get_permalink( $ids[0] ), $urls );
		$this->assertCount( 3, UrlCollector::collect( 3 ) );
		$this->assertFalse( UrlCollector::is_own_url( 'https://evil.example/' ) );
		$this->assertFalse( UrlCollector::is_own_url( 'ftp://' . wp_parse_url( home_url(), PHP_URL_HOST ) . '/' ) );
	}

	/* ------------------------------------------------------------ fetching */

	public function test_fetch_follows_redirects_inside_the_site_and_refuses_other_hosts() {
		$this->pages['/old'] = $this->response( 301, '', array( 'location' => home_url( '/new' ) ) );
		$this->pages['/new'] = $this->response( 200, '<html>LittleD new</html>', array( 'set-cookie' => array( 'LittleD_hop=1; Max-Age=60; Path=/' ) ) );
		$page                = ServerScanner::fetch( home_url( '/old' ) );
		$this->assertSame( 200, $page['code'] );
		$this->assertSame( '', $page['error'] );
		$this->assertStringContainsString( 'LittleD new', $page['html'] );
		$this->assertSame( 'LittleD_hop', $page['cookies'][0]['name'] );

		$this->requests      = array();
		$this->pages['/out'] = $this->response( 302, '', array( 'location' => 'https://evil.example/x' ) );
		$page                = ServerScanner::fetch( home_url( '/out' ) );
		$this->assertSame( 'foreign_host', $page['error'] );
		foreach ( $this->requests as $url ) {
			$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( $url, PHP_URL_HOST ), 'no request left the site' );
		}

		$this->pages['/loop'] = $this->response( 302, '', array( 'location' => home_url( '/loop' ) ) );
		$this->assertSame( 'too_many_redirects', ServerScanner::fetch( home_url( '/loop' ) )['error'] );
		$this->assertSame( 'foreign_host', ServerScanner::fetch( 'https://evil.example/' )['error'] );
	}

	public function test_requests_carry_a_valid_token_a_browser_user_agent_and_no_cookies() {
		$seen = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$seen ) {
				$seen = array( $args, $url );
				return $pre;
			},
			5,
			3
		);
		ServerScanner::fetch( home_url( '/x' ) );
		$this->assertStringContainsString( 'Mozilla/5.0', $seen[0]['user-agent'] );
		$this->assertSame( array(), $seen[0]['cookies'] );
		$this->assertSame( 0, $seen[0]['redirection'] );
		$this->assertSame( 15, $seen[0]['timeout'] );
		parse_str( (string) wp_parse_url( $seen[1], PHP_URL_QUERY ), $query );
		$this->assertTrue( ScanRequest::valid( $query[ ScanRequest::PARAM ] ) );
		$this->assertFalse( ScanRequest::valid( 'x' ) );
		$this->assertFalse( ScanRequest::valid( str_repeat( 'a', 24 ) ) );
		$this->assertFalse( ScanRequest::valid( null ) );
	}

	public function test_set_cookie_values_reads_repeated_headers_from_a_real_response_object() {
		$this->assertSame( array( 'a=1', 'b=2' ), ServerScanner::set_cookie_values( $this->response( 200, '', array( 'set-cookie' => array( 'a=1', 'b=2' ) ) ) ) );
		$this->assertSame( array( 'a=1' ), ServerScanner::set_cookie_values( $this->response( 200, '', array( 'set-cookie' => 'a=1' ) ) ) );
		$this->assertSame( array(), ServerScanner::set_cookie_values( $this->response( 200, '' ) ) );
	}

	/* ------------------------------------------------------------ a whole run */

	public function test_a_scan_run_records_cookies_platforms_and_gtm_tags() {
		self::factory()->post->create_many( 6, array( 'post_status' => 'publish' ) );
		$this->pages['/']       = $this->response(
			200,
			$this->home_html(),
			array(
				'set-cookie'  => array(
					'_fbp=fb.1.LittleDSecretValue; Max-Age=7776000; Path=/',
					'wp-wpml_current_language=vi; Path=/',
					'LittleD_unknown=LittleDSecretValue; Max-Age=86400; Path=/; HttpOnly',
				),
				'x-dscc-held' => 'LittleD_held',
			)
		);
		$start = ScanRunner::start( 'manual' );
		$this->assertIsArray( $start );
		$this->assertGreaterThanOrEqual( 7, $start['total'] );
		$this->assertFalse( $start['resumed'] );
		$again = ScanRunner::start( 'manual' );
		$this->assertTrue( $again['resumed'], 'a running scan is reused' );
		$this->assertSame( $start['id'], $again['id'] );

		$first = ScanRunner::tick( $start['id'] );
		$this->assertFalse( $first['finished'] );
		$this->assertSame( 5, $first['done'], 'a batch is five pages' );
		$this->assertNotFalse( wp_next_scheduled( 'dscc_scan_tick', array( $start['id'] ) ), 'the next batch is chained through cron' );

		$st = $this->run_to_end( $start['id'] );
		$this->assertSame( 'done', $st['status'] );
		$this->assertSame( $start['total'], $st['done'] );
		$this->assertSame( 0, $st['failed'] );

		// Cookies: merge, classification, new count.
		$this->assertSame( 'meta-pixel', ServiceRepository::find_by_slug( 'meta-pixel' )['slug'] );
		$unknown = CookieRepository::find_identity( 'LittleD_unknown', 'cookie', '' );
		$this->assertSame( 'new', $unknown['status'] );
		$this->assertSame( 1, $unknown['http_only'] );
		$held = CookieRepository::find_identity( 'LittleD_held', 'cookie', '' );
		$this->assertNotNull( $held, 'a cookie the guard held back is still known to the catalog' );
		$this->assertSame( 2, $st['new_cookies'], 'LittleD_unknown and LittleD_held' );
		$this->assertSame( 2, CookieRepository::count_new() );
		$this->assertGreaterThan( 1, $unknown['seen_count'], 'seen on every page that sets it' );
		$this->assertSame( array( '/' ), array_slice( json_decode( $unknown['seen_on_urls'], true ), 0, 1 ) );

		// Platforms: HTML and GTM.
		$ga = ServiceRepository::find_by_slug( 'google-analytics' );
		$this->assertContains( 'html', $ga['detected_via'] );
		$this->assertContains( 'html', ServiceRepository::find_by_slug( 'meta-pixel' )['detected_via'] );
		$this->assertContains( 'html', ServiceRepository::find_by_slug( 'microsoft-clarity' )['detected_via'] );
		$this->assertContains( 'html', ServiceRepository::find_by_slug( 'google-tag-manager' )['detected_via'] );
		$this->assertContains( 'gtm', ServiceRepository::find_by_slug( 'hotjar' )['detected_via'], 'a platform only the container loads is "via GTM"' );
		$this->assertContains( 'gtm', ServiceRepository::find_by_slug( 'google-ads' )['detected_via'] );

		// Report.
		$run = ScanRunRepository::find( $start['id'] );
		$this->assertSame( 'server', $run['type'] );
		$this->assertArrayNotHasKey( 'queue', $run['report'] );
		$this->assertSame( 'GTM-LITTLED', $run['report']['gtm'][0]['id'] );
		$this->assertTrue( $run['report']['gtm'][0]['ok'] );
		$this->assertContains( 'G-LITTLED000', $run['report']['gtm'][0]['tags'] );
		$this->assertContains( 'cdn.littled.example', $run['report']['unknown_hosts'] );
		$this->assertTrue( $run['report']['cookies']['LittleD_held']['held'] );
		$this->assertFalse( $run['report']['cookies']['_fbp']['held'] );
		$this->assertNotEmpty( get_option( 'dscc_last_scan' ) );

		// Only the site and the one Tag Manager address were requested.
		$gtm_requests = 0;
		foreach ( $this->requests as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( 'www.googletagmanager.com' === $host ) {
				++$gtm_requests;
				$this->assertSame( '/gtm.js', wp_parse_url( $url, PHP_URL_PATH ) );
				$this->assertSame( 'id=GTM-LITTLED', wp_parse_url( $url, PHP_URL_QUERY ) );
			} else {
				$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), $host );
			}
		}
		$this->assertSame( 1, $gtm_requests );

		// No cookie value reached the database.
		$dump = wp_json_encode( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Schema::cookies_table(), ARRAY_A ) ) . wp_json_encode( $GLOBALS['wpdb']->get_results( 'SELECT * FROM ' . Schema::scan_runs_table(), ARRAY_A ) ) . wp_json_encode( $GLOBALS['wpdb']->get_results( 'SELECT option_value FROM ' . $GLOBALS['wpdb']->options, ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertStringNotContainsString( 'LittleDSecretValue', $dump );
	}

	public function test_a_scan_where_no_page_loads_fails_cleanly() {
		$this->pages['/'] = new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' );
			}
		);
		$start = ScanRunner::start( 'manual' );
		$st    = $this->run_to_end( $start['id'] );
		$this->assertSame( 'failed', $st['status'] );
		$this->assertSame( 'no_page_loaded', $st['message'] );
		$this->assertSame( 0, (int) get_option( 'dscc_last_scan', 0 ) );
	}

	public function test_a_stale_running_scan_is_closed_and_replaced() {
		$old = ScanRunRepository::create( 'server', array( 'total' => 5, 'done' => 1, 'failed' => 0, 'cookies' => 0, 'new_cookies' => 0, 'touched' => time() - 4000 ), array( 'queue' => array( home_url( '/' ) ) ) );
		$new = ScanRunner::start( 'manual' );
		$this->assertNotSame( $old, $new['id'] );
		$this->assertSame( 'failed', ScanRunRepository::find( $old )['status'] );
	}

	public function test_a_held_lock_defers_the_batch() {
		$start = ScanRunner::start( 'manual' );
		add_option( 'dscc_scan_lock', time(), '', 'no' );
		$st = ScanRunner::tick( $start['id'] );
		$this->assertSame( 0, $st['done'], 'another worker holds the lock' );
		delete_option( 'dscc_scan_lock' );
		$this->assertGreaterThan( 0, ScanRunner::tick( $start['id'] )['done'] );
	}

	public function test_old_runs_are_pruned() {
		for ( $i = 0; $i < 23; $i++ ) {
			ScanRunRepository::create( 'server', array(), array(), 'done' );
		}
		$n = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Schema::scan_runs_table() . " WHERE type = 'server'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 20, $n, 'only the newest 20 runs of a type are kept' );
	}

	/* ------------------------------------------------------------ container reader */

	public function test_container_reader_only_accepts_container_ids_and_one_address() {
		$this->assertFalse( GtmContainerReader::valid_id( 'GTM-../evil' ) );
		$this->assertFalse( GtmContainerReader::valid_id( 'gtm-abcd' ) );
		$this->assertTrue( GtmContainerReader::valid_id( 'GTM-LITTLED' ) );
		$this->requests = array();
		$bad            = GtmContainerReader::read( 'https://evil.example/' );
		$this->assertFalse( $bad['ok'] );
		$this->assertSame( array(), $this->requests );
		$ok = GtmContainerReader::read( 'GTM-LITTLED' );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( array( 'https://www.googletagmanager.com/gtm.js?id=GTM-LITTLED' ), $this->requests );
	}

	/* ------------------------------------------------------------ email */

	public function test_email_is_sent_once_a_day_and_only_when_enabled() {
		Settings::save_group( 'scanner', array( 'notify_enabled' => '1', 'notify_email' => 'littled@example.test' ) );
		FindingStore::merge_cookies( array( array( 'name' => 'LittleD_unknown', 'set_by' => 'server' ) ), array( 'via' => 'server' ) );

		$this->assertFalse( ScanNotifier::maybe_send( array( 'new_cookies' => 0 ), 1000 ), 'nothing new, nothing sent' );
		$this->assertTrue( ScanNotifier::maybe_send( array( 'new_cookies' => 1 ), 1000 ) );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'littled@example.test', $mail->to[0][0] );
		$this->assertStringContainsString( 'LittleD_unknown', $mail->body );
		$this->assertStringContainsString( '1', $mail->subject );

		reset_phpmailer_instance();
		$this->assertFalse( ScanNotifier::maybe_send( array( 'new_cookies' => 1 ), 1000 + 3600 ), 'throttled to one per day' );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
		$this->assertTrue( ScanNotifier::maybe_send( array( 'new_cookies' => 1 ), 1000 + 86400 + 1 ) );

		reset_phpmailer_instance();
		delete_option( 'dscc_scan_mail_at' );
		Settings::save_group( 'scanner', array( 'notify_enabled' => '' ) );
		$this->assertFalse( ScanNotifier::maybe_send( array( 'new_cookies' => 1 ), 5000 ), 'the toggle switches it off' );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	public function test_email_goes_to_the_admin_address_by_default() {
		Settings::save_group( 'scanner', array( 'notify_enabled' => '1', 'notify_email' => '' ) );
		$this->assertSame( get_option( 'admin_email' ), ScanNotifier::recipient() );
		Settings::save_group( 'scanner', array( 'notify_enabled' => '1', 'notify_email' => 'not-an-email' ) );
		$this->assertSame( '', Settings::get( 'scanner', 'notify_email' ), 'an invalid address is refused' );
		$this->assertSame( get_option( 'admin_email' ), ScanNotifier::recipient() );
	}

	/* ------------------------------------------------------------ weekly schedule */

	public function test_the_weekly_scan_follows_the_setting() {
		wp_clear_scheduled_hook( 'dscc_scan_weekly' );
		Settings::save_group( 'scanner', array( 'auto_scan' => 'weekly' ) );
		ScanScheduler::sync();
		$this->assertNotFalse( wp_next_scheduled( 'dscc_scan_weekly' ) );
		$this->assertSame( 'weekly', wp_get_schedule( 'dscc_scan_weekly' ) );
		Settings::save_group( 'scanner', array( 'auto_scan' => 'off' ) );
		ScanScheduler::sync();
		$this->assertFalse( wp_next_scheduled( 'dscc_scan_weekly' ) );
	}

	public function test_the_weekly_callback_starts_a_scan_only_when_enabled() {
		Settings::save_group( 'scanner', array( 'auto_scan' => 'off' ) );
		ScanRunner::weekly();
		$this->assertNull( ScanRunRepository::latest( 'server', 'running' ) );
		Settings::save_group( 'scanner', array( 'auto_scan' => 'weekly' ) );
		ScanRunner::weekly();
		$run = ScanRunRepository::latest( 'server', 'running' );
		$this->assertSame( 'cron', $run['summary']['trigger'] );
	}
}
