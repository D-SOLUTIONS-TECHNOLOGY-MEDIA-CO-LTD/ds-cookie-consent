<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Consent\ConsentLogRepository;
use DSolutions\CookieConsent\Consent\LogEndpoint;
use DSolutions\CookieConsent\Consent\RateLimitStats;
use DSolutions\CookieConsent\Database\Schema;
use DSolutions\CookieConsent\Settings\Settings;

class Dscc_Log_Endpoint_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
		$_SERVER['HTTP_ORIGIN']     = home_url();
		$_SERVER['REMOTE_ADDR']     = '203.0.113.77';
		$_SERVER['HTTP_USER_AGENT'] = 'LittleD-Browser/1.0';
		unset( $_SERVER['HTTP_REFERER'] );
	}

	public function tear_down() {
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );
		delete_option( RateLimitStats::OPTION );
		parent::tear_down();
	}

	private function params( array $over = array() ) {
		return array_merge(
			array(
				'id'     => 'LittleD-0001-aaaa',
				'choice' => 'custom',
				'c'      => '{"analytics":1,"marketing":0}',
				's'      => '',
				'v'      => '2026-11',
				'lang'   => 'vi',
				'url'    => '/gia?x=1',
				'src'    => 'widget',
			),
			$over
		);
	}

	private function rows() {
		global $wpdb;
		$table = Schema::log_table();
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_valid_request_inserts_masked_row() {
		$result = LogEndpoint::process( $this->params(), 300 );
		$this->assertSame( 200, $result['status'] );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertSame( 'custom', $rows[0]['choice'] );
		$this->assertSame( '1', $rows[0]['analytics'] );
		$this->assertSame( '0', $rows[0]['marketing'] );
		$this->assertSame( 'widget', $rows[0]['src'] );
		$this->assertSame( '/gia', $rows[0]['page_url'] );
		$this->assertSame( '203.0.113.0', $rows[0]['ip_masked'] );
		$this->assertSame( 'LittleD-Browser/1.0', $rows[0]['user_agent'] );
	}

	public function test_foreign_origin_rejected() {
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$this->assertSame( 403, LogEndpoint::process( $this->params(), 300 )['status'] );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_oversized_body_rejected() {
		$this->assertSame( 413, LogEndpoint::process( $this->params(), 5000 )['status'] );
	}

	public function test_invalid_payload_rejected() {
		$this->assertSame( 400, LogEndpoint::process( $this->params( array( 'choice' => 'accept' ) ), 300 )['status'] );
		$this->assertCount( 0, $this->rows() );
	}

	public function test_rate_limit_is_ten_per_minute_per_masked_ip() {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertSame( 200, LogEndpoint::process( $this->params(), 300 )['status'], "request $i" );
		}
		$this->assertSame( 429, LogEndpoint::process( $this->params(), 300 )['status'] );
		$this->assertCount( 10, $this->rows() );

		// Another address in the same /24 shares the bucket; a different network does not.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$this->assertSame( 429, LogEndpoint::process( $this->params(), 300 )['status'] );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$this->assertSame( 200, LogEndpoint::process( $this->params(), 300 )['status'] );
	}

	public function test_rejections_are_counted_per_day_for_the_overview() {
		for ( $i = 1; $i <= 10; $i++ ) {
			LogEndpoint::process( $this->params(), 300 );
		}
		$this->assertSame( 0, RateLimitStats::today() );
		LogEndpoint::process( $this->params(), 300 );
		LogEndpoint::process( $this->params(), 300 );
		$this->assertSame( 2, RateLimitStats::today() );
		$this->assertSame( 2, RateLimitStats::week() );
		$this->assertSame( array( gmdate( 'Y-m-d' ) => 2 ), RateLimitStats::all() );
	}

	public function test_stats_ignore_days_older_than_a_week() {
		update_option( RateLimitStats::OPTION, array( gmdate( 'Y-m-d' ) => 3, gmdate( 'Y-m-d', time() - 9 * DAY_IN_SECONDS ) => 50 ), false );
		$this->assertSame( 3, RateLimitStats::week() );
	}

	public function test_proxy_header_is_ignored_unless_configured() {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.40';
		LogEndpoint::process( $this->params(), 300 );
		$this->assertSame( '10.0.0.0', $this->rows()[0]['ip_masked'] );
	}

	public function test_trusted_proxy_header_sets_masked_ip_and_rate_limit_bucket() {
		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'trusted_ip_header' => 'cf-connecting-ip' ) ) );
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1'; // The edge proxy: shared by every visitor.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.40';
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->assertSame( 200, LogEndpoint::process( $this->params(), 300 )['status'] );
		}
		$this->assertSame( 429, LogEndpoint::process( $this->params(), 300 )['status'] );
		$this->assertSame( '198.51.100.0', $this->rows()[0]['ip_masked'] );

		// A visitor on another network is not affected by the first one's bucket.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '192.0.2.7';
		$this->assertSame( 200, LogEndpoint::process( $this->params(), 300 )['status'] );
		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'trusted_ip_header' => 'none' ) ) );
	}

	public function test_rest_mirror_inserts() {
		$request = new WP_REST_Request( 'POST', '/dscc/v1/log' );
		$request->set_body_params( $this->params() );
		$request->set_header( 'content-length', '300' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $this->rows() );
	}

	public function test_retention_cleanup_deletes_only_old_rows() {
		LogEndpoint::process( $this->params(), 300 );
		ConsentLogRepository::insert(
			array(
				'consent_id'     => 'LittleD-old-0001',
				'choice'         => 'reject_all',
				'analytics'      => 0,
				'marketing'      => 0,
				'policy_version' => '2024-01',
				'lang'           => 'vi',
				'page_url'       => '/',
				'src'            => 'banner',
				'created_at'     => gmdate( 'Y-m-d H:i:s', strtotime( '-30 months' ) ),
			)
		);
		$this->assertCount( 2, $this->rows() );
		$this->assertSame( 1, ConsentLogRepository::delete_older_than( 24 ) );
		$this->assertCount( 1, $this->rows() );
		$this->assertSame( 0, ConsentLogRepository::delete_older_than( 0 ) );
	}
}
