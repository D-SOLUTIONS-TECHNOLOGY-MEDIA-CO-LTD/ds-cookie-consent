<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Admin\ComplianceReport;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Blocker\ScriptRules;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Scanner\AlertStore;
use DSolutions\CookieConsent\Scanner\CookieLookup;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Scanner\ScanRunRepository;

/**
 * POST /dscc/v1/scan-result: authentication, size and shape limits, the merge into the catalog, the stored run,
 * the violations and their reflection in the compliance report.
 */
class Dscc_Deep_Scan_Endpoint_Test extends WP_UnitTestCase {

	private $admin;

	public function set_up() {
		parent::set_up();
		Activator::install();
		FindingStore::reset();
		FrontendCatalogCache::rebuild();
		delete_option( AlertStore::OPTION );
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	private function cookie( $name, $domain = null, $expires = -1 ) {
		return array(
			'name'     => $name,
			'domain'   => null === $domain ? wp_parse_url( home_url(), PHP_URL_HOST ) : $domain,
			'path'     => '/',
			'httpOnly' => false,
			'secure'   => false,
			'sameSite' => 'Lax',
			'expires'  => $expires,
		);
	}

	private function phase( array $cookies = array(), array $hosts = array(), array $set = array() ) {
		return array(
			'pages'       => array( home_url( '/' ) ),
			'cookies'     => $cookies,
			'storage'     => array(
				'local'   => array(),
				'session' => array(),
			),
			'hosts'       => $hosts,
			'set_cookies' => $set,
		);
	}

	private function report( array $phases = array() ) {
		return array(
			'schema'          => 1,
			'site'            => untrailingslashit( home_url() ),
			'scanned_at'      => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'tool_version'    => '1.0.0',
			'plugin_detected' => true,
			'banner_found'    => true,
			'phases'          => array_merge(
				array(
					'before_choice' => $this->phase(),
					'after_reject'  => $this->phase(),
					'after_accept'  => $this->phase(),
				),
				$phases
			),
			'errors'          => array(),
		);
	}

	private function row( $name ) {
		return CookieLookup::find( CookieLookup::rows( array( $name ), 'cookie' ), $name );
	}

	private function post( $body ) {
		$request = new WP_REST_Request( 'POST', '/dscc/v1/scan-result' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( is_string( $body ) ? $body : wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	private function dirty_report() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return $this->report(
			array(
				'before_choice' => $this->phase(
					array( $this->cookie( '_fbp', null, time() + 7776000 ), $this->cookie( 'LittleD_unknown' ), $this->cookie( 'LittleD_necessary_x' ) ),
					array( $host, 'www.clarity.ms' ),
					array( array( 'host' => $host, 'names' => array( '_fbp', 'LittleD_unknown' ) ) )
				),
				'after_reject'  => $this->phase( array( $this->cookie( '_fbp' ) ) ),
				'after_accept'  => $this->phase( array( $this->cookie( '_ga' ) ) ),
			)
		);
	}

	public function test_no_authentication_is_401() {
		wp_set_current_user( 0 );
		$res = $this->post( $this->report() );
		$this->assertSame( 401, $res->get_status() );
		$this->assertNull( ScanRunRepository::latest( 'deep', '' ) );
	}

	public function test_a_user_without_manage_options_is_403() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$res = $this->post( $this->report() );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_a_body_over_2_mb_is_413() {
		$res = $this->post( wp_json_encode( array( 'schema' => 1, 'pad' => str_repeat( 'x', 2097153 ) ) ) );
		$this->assertSame( 413, $res->get_status() );
	}

	public function test_the_size_limit_runs_before_authentication_and_exactly_2_mb_passes_it() {
		wp_set_current_user( 0 );
		$head  = '{"schema":1,"pad":"';
		$tail  = '"}';
		$exact = $head . str_repeat( 'x', 2097152 - strlen( $head ) - strlen( $tail ) ) . $tail;
		$this->assertSame( 2097152, strlen( $exact ) );
		$this->assertSame( 401, $this->post( $exact )->get_status(), 'exactly 2 MB is not refused for size; anonymous is refused for authentication' );
		$this->assertSame( 413, $this->post( $exact . ' ' )->get_status(), 'one byte more is refused for size before authentication' );
	}

	public function test_malformed_json_is_400() {
		$this->assertSame( 400, $this->post( '{"schema": 1,' )->get_status() );
	}

	public function test_unknown_schema_and_unknown_fields_are_400() {
		$bad           = $this->report();
		$bad['schema'] = 2;
		$res           = $this->post( $bad );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'dscc_unsupported_schema', $res->get_data()['code'] );

		$valued = $this->report( array( 'before_choice' => $this->phase( array( array_merge( $this->cookie( 'LittleD_v' ), array( 'value' => 'LittleDSecretValue' ) ) ) ) ) );
		$res    = $this->post( $valued );
		$this->assertSame( 400, $res->get_status() );
		$this->assertNull( $this->row( 'LittleD_v' ) );
		$this->assertNull( ScanRunRepository::latest( 'deep', '' ) );
	}

	public function test_a_report_of_another_site_is_422() {
		$other         = $this->report();
		$other['site'] = 'https://other.littled.example';
		$this->assertSame( 422, $this->post( $other )->get_status() );
	}

	public function test_a_valid_report_stores_the_run_merges_cookies_and_returns_the_counts() {
		$res = $this->post( $this->dirty_report() );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 1, $data['violations']['after_reject'] );

		$run = ScanRunRepository::latest( 'deep', 'done' );
		$this->assertSame( $data['run_id'], $run['id'] );
		$this->assertNotEmpty( $run['finished_at'] );
		$this->assertTrue( $run['summary']['banner_found'] );
		$this->assertSame( 1, $run['summary']['pages'] );
		$this->assertStringNotContainsString( 'LittleDSecret', (string) wp_json_encode( $run['report'] ) );

		$fbp = $this->row( '_fbp' );
		$this->assertNotNull( $fbp );
		$this->assertSame( 'server', $fbp['set_by'] );
		$unknown = $this->row( 'LittleD_unknown' );
		$this->assertSame( 'new', $unknown['status'] );
		$this->assertSame( 'server', $unknown['set_by'], 'Set-Cookie evidence from an own host makes it a server cookie' );
		$this->assertSame( 'client', $this->row( 'LittleD_necessary_x' )['set_by'] );
		$this->assertNotNull( $this->row( '_ga' ), 'cookies of the accepted phase are catalogued too' );
	}

	public function test_violations_come_from_before_choice_and_after_reject_only_and_are_alerts() {
		$this->post( $this->dirty_report() );
		$run = ScanRunRepository::latest( 'deep', 'done' );
		$before = array_column( $run['report']['phases']['before_choice']['violations'], 'name' );
		sort( $before );
		$this->assertSame( array( '_fbp', 'www.clarity.ms' ), $before );
		$this->assertSame( array( '_fbp' ), array_column( $run['report']['phases']['after_reject']['violations'], 'name' ) );
		$this->assertSame( array(), $run['report']['phases']['after_accept']['violations'] );

		$names = array_column( AlertStore::leaks(), 'name' );
		$this->assertContains( '_fbp', $names );
		$this->assertContains( 'www.clarity.ms', $names );
		$this->assertNotContains( '_ga', $names );
		$this->assertSame( 'deep', AlertStore::leaks()[0]['source'] );
	}

	public function test_the_compliance_report_shows_the_last_deep_scan() {
		$this->assertNull( ComplianceReport::build()['deep'] );
		$this->post( $this->dirty_report() );
		$deep = ComplianceReport::build()['deep'];
		$this->assertSame( untrailingslashit( home_url() ), $deep['site'] );
		$this->assertSame( 1, $deep['pages'] );
		$this->assertTrue( $deep['banner_found'] );
		$this->assertSame( 3, $deep['count'] );
		$this->assertSame( array( '_fbp' ), array_column( $deep['violations']['after_reject'], 'name' ) );
	}

	public function test_a_clean_scan_replaces_the_alerts_of_the_previous_deep_scan() {
		$this->post( $this->dirty_report() );
		$this->assertNotEmpty( AlertStore::leaks() );
		AlertStore::record_leaks( array( array( 'name' => 'LittleD_beacon', 'storage' => 'cookie', 'category' => 'marketing', 'service_slug' => '' ) ), 'beacon' );

		$res = $this->post( $this->report( array( 'before_choice' => $this->phase( array( $this->cookie( 'LittleD_necessary_x' ) ) ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'LittleD_beacon' ), array_column( AlertStore::leaks(), 'name' ), 'only the beacon alert is left' );
		$this->assertSame( 0, ComplianceReport::build()['deep']['count'] );
	}

	public function test_an_ignored_or_necessary_classification_is_not_a_violation() {
		$this->post( $this->report( array( 'before_choice' => $this->phase( array( $this->cookie( '_fbp' ) ) ) ) ) );
		$id = $this->row( '_fbp' )['id'];
		\DSolutions\CookieConsent\Catalog\CookieActions::ignore( array( $id ) );
		AlertStore::clear();
		$res = $this->post( $this->report( array( 'before_choice' => $this->phase( array( $this->cookie( '_fbp' ) ) ) ) ) );
		$this->assertSame( 0, $res->get_data()['violations']['before_choice'] );
	}

	public function test_names_that_are_not_literal_are_skipped_not_stored() {
		$res = $this->post( $this->report( array( 'before_choice' => $this->phase( array( $this->cookie( 'LittleD_*' ), $this->cookie( 'LittleD_fine' ) ) ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, $res->get_data()['skipped'] );
		$this->assertNull( $this->row( 'LittleD_*' ) );
	}

	private function render_report() {
		$compliance = ComplianceReport::build();
		ob_start();
		require DSCC_DIR . 'templates/admin/compliance-block.php';
		return ob_get_clean();
	}

	/* ------------------------------------------------------------ conclusive scans */

	public function test_a_scan_without_any_loaded_page_is_400() {
		$res = $this->post( $this->report( array( 'before_choice' => array_merge( $this->phase(), array( 'pages' => array() ) ) ) ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertNull( ScanRunRepository::latest( 'deep', '' ) );
	}

	public function test_scanned_at_must_be_recent_iso_8601() {
		foreach ( array( 'yesterday', gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d\TH:i:s\Z', time() + 1200 ), gmdate( 'Y-m-d\TH:i:s\Z', time() - 31 * DAY_IN_SECONDS ) ) as $value ) {
			$report               = $this->report();
			$report['scanned_at'] = $value;
			$this->assertSame( 400, $this->post( $report )->get_status(), $value );
		}
		$this->assertNull( ScanRunRepository::latest( 'deep', '' ) );
	}

	public function test_the_summary_says_whether_the_scan_was_conclusive() {
		$this->assertTrue( $this->post( $this->report() )->get_data()['conclusive'] );
		$this->assertTrue( ScanRunRepository::latest( 'deep', 'done' )['summary']['conclusive'] );

		$blind                 = $this->report();
		$blind['banner_found'] = false;
		$this->assertFalse( $this->post( $blind )->get_data()['conclusive'] );
		$summary = ScanRunRepository::latest( 'deep', 'done' )['summary'];
		$this->assertFalse( $summary['conclusive'] );
		$this->assertSame( 'no_banner', $summary['reason'] );

		$no_plugin                    = $this->report();
		$no_plugin['plugin_detected'] = false;
		$this->post( $no_plugin );
		$this->assertSame( 'no_plugin', ScanRunRepository::latest( 'deep', 'done' )['summary']['reason'] );
	}

	public function test_an_inconclusive_scan_warns_instead_of_ok_and_keeps_the_earlier_deep_alerts() {
		$this->post( $this->dirty_report() );
		$before = array_column( AlertStore::leaks(), 'name' );
		$this->assertContains( '_fbp', $before );

		$blind                 = $this->report();
		$blind['banner_found'] = false;
		$this->post( $blind );

		$this->assertEquals( $before, array_column( AlertStore::leaks(), 'name' ), 'the earlier deep alerts survive a scan that could not see the banner' );
		$deep = ComplianceReport::build()['deep'];
		$this->assertFalse( $deep['conclusive'] );
		$html = $this->render_report();
		$this->assertStringContainsString( 'id="dscc-deep-inconclusive"', $html );
		$this->assertStringContainsString( 'The scan was inconclusive: no Reject / Accept button found on the banner', $html );
		$this->assertStringNotContainsString( 'id="dscc-deep-ok"', $html );
	}

	public function test_a_conclusive_clean_scan_shows_ok_and_the_age_in_days() {
		$this->post( $this->report() );
		$html = $this->render_report();
		$this->assertStringContainsString( 'id="dscc-deep-ok"', $html );
		$this->assertStringNotContainsString( 'dscc-deep-inconclusive', $html );
		$this->assertStringContainsString( 'Scanned today.', $html );

		global $wpdb;
		$run = ScanRunRepository::latest( 'deep', 'done' );
		$wpdb->update( \DSolutions\CookieConsent\Database\Schema::scan_runs_table(), array( 'finished_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS - 60 ) ), array( 'id' => $run['id'] ) );
		$this->assertStringContainsString( 'Scanned 3 days ago.', $this->render_report() );
	}

	public function test_a_missing_finish_time_falls_back_to_the_scan_time() {
		global $wpdb;
		$this->post( $this->report() );
		$run = ScanRunRepository::latest( 'deep', 'done' );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . \DSolutions\CookieConsent\Database\Schema::scan_runs_table() . ' SET finished_at = NULL WHERE id = %d', $run['id'] ) ); // phpcs:ignore WordPress.DB
		$deep = ComplianceReport::build()['deep'];
		$this->assertGreaterThan( time() - 600, strtotime( $deep['finished_at'] . ' UTC' ) );
		$this->assertStringNotContainsString( '1970', $this->render_report() );
	}

	/* ------------------------------------------------------------ vendor hosts */

	public function test_a_host_only_contact_with_a_vendor_is_a_violation() {
		$res = $this->post( $this->report( array( 'before_choice' => $this->phase( array(), array( 'connect.facebook.net' ) ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 1, $res->get_data()['violations']['before_choice'] );
		$run = ScanRunRepository::latest( 'deep', 'done' );
		$this->assertSame( array( 'connect.facebook.net' ), array_column( $run['report']['phases']['before_choice']['violations'], 'name' ) );
		$this->assertSame( 'marketing', $run['report']['phases']['before_choice']['violations'][0]['category'] );
	}

	public function test_a_cookieless_google_consent_mode_ping_is_a_warning_without_alert_or_email() {
		reset_phpmailer_instance();
		delete_option( \DSolutions\CookieConsent\Scanner\DeepScanAlertMailer::OPTION );
		$hosts = array( 'www.google-analytics.com', 'www.googleadservices.com' );
		$res   = $this->post(
			$this->report(
				array(
					'before_choice' => $this->phase( array(), $hosts ),
					'after_reject'  => $this->phase( array(), $hosts ),
				)
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'before_choice' => 0, 'after_reject' => 0 ), $res->get_data()['violations'] );
		$this->assertSame( array( 'before_choice' => 2, 'after_reject' => 2 ), $res->get_data()['warnings'] );
		$this->assertSame( array(), AlertStore::leaks(), 'a ping is not a leak alert' );
		$this->assertCount( 0, tests_retrieve_phpmailer_instance()->mock_sent, 'a ping does not send the violation email' );
		$run = ScanRunRepository::latest( 'deep', 'done' );
		$this->assertSame( $hosts, array_column( $run['report']['phases']['before_choice']['warnings'], 'name' ) );

		$html = $this->render_report();
		$this->assertStringContainsString( 'id="dscc-deep-warnings"', $html );
		$this->assertStringContainsString( 'data-dscc-deep-warning="www.google-analytics.com"', $html );
		$this->assertSame( 1, substr_count( $html, 'data-dscc-deep-warning="www.google-analytics.com"' ), 'listed once for both phases' );
		$this->assertStringContainsString( 'id="dscc-deep-ok"', $html );
		$this->assertStringNotContainsString( 'data-dscc-deep-violation', $html );
	}

	public function test_a_google_host_with_a_google_cookie_stays_a_violation() {
		$res = $this->post( $this->report( array( 'before_choice' => $this->phase( array( $this->cookie( '_ga' ) ), array( 'www.google-analytics.com' ) ) ) ) );
		$this->assertSame( 2, $res->get_data()['violations']['before_choice'] );
		$this->assertSame( 0, $res->get_data()['warnings']['before_choice'] );
	}

	public function test_platforms_created_by_the_scan_are_returned_and_listed_on_the_card() {
		$res = $this->post( $this->report( array( 'after_accept' => $this->phase( array(), array( 'www.clarity.ms' ) ) ) ) );
		$this->assertSame(
			array(
				array(
					'slug'     => 'microsoft-clarity',
					'name'     => 'Microsoft Clarity',
					'category' => 'analytics',
				),
			),
			$res->get_data()['platforms_created']
		);
		$html = $this->render_report();
		$this->assertStringContainsString( 'data-dscc-deep-platform="microsoft-clarity"', $html );
		$this->assertStringContainsString( 'tab=platforms', $html );

		$again = $this->post( $this->report( array( 'after_accept' => $this->phase( array(), array( 'www.clarity.ms' ) ) ) ) );
		$this->assertSame( array(), $again->get_data()['platforms_created'], 'only platforms that did not exist are listed' );
	}

	public function test_necessary_and_functional_vendors_are_created_unblocked_and_never_violations() {
		$res = $this->post( $this->report( array( 'before_choice' => $this->phase( array(), array( 'www.recaptcha.net', 'maps.googleapis.com' ) ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$recaptcha = ServiceRepository::find_by_slug( 'google-recaptcha' );
		$this->assertSame( 'necessary', $recaptcha['category_key'] );
		$this->assertSame( 0, (int) $recaptcha['block_enabled'] );
		ScriptRules::flush();
		$this->assertNotContains( 'google-recaptcha', array_column( ScriptRules::active()['rules'], 's' ) );
		$names = array_column( ScanRunRepository::latest( 'deep', 'done' )['report']['phases']['before_choice']['violations'], 'name' );
		$this->assertNotContains( 'www.recaptcha.net', $names );
		// Maps is functional and third-party: it is blocked until the Functional group is allowed, but contacting it
		// before a choice is not a violation (only analytics and marketing are).
		$this->assertSame( 'functional', ServiceRepository::find_by_slug( 'google-maps' )['category_key'] );
		$this->assertSame( 1, (int) ServiceRepository::find_by_slug( 'google-maps' )['block_enabled'] );
		$this->assertNotContains( 'maps.googleapis.com', $names );
		foreach ( ServiceRepository::all() as $service ) {
			if ( 'necessary' === $service['category_key'] || ( 'functional' === $service['category_key'] && ! $service['is_third_party'] ) ) {
				$this->assertSame( 0, (int) $service['block_enabled'], $service['slug'] );
			}
		}
	}

	/* ------------------------------------------------------------ own hosts */

	public function test_the_own_host_filter_lets_an_internal_name_through() {
		$report         = $this->report();
		$report['site'] = 'http://web.internal.littled.example:8080';
		$this->assertSame( 422, $this->post( $report )->get_status() );
		add_filter(
			'dscc_scan_own_hosts',
			static function ( $hosts ) {
				$hosts[] = 'web.internal.littled.example';
				$hosts[] = 'bad host <script>';
				return $hosts;
			}
		);
		$this->assertSame( 200, $this->post( $report )->get_status() );
		$this->assertNotContains( 'bad host <script>', \DSolutions\CookieConsent\Scanner\DeepScanIngest::own_hosts() );
		remove_all_filters( 'dscc_scan_own_hosts' );
	}
}
