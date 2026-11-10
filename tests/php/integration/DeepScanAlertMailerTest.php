<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Scanner\DeepScanAlertMailer;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Email about deep scan violations: sent when there is at least one, not for a clean scan, not repeated for the
 * same set within a day, resent for a changed set, switched off by the toggle, and triggered by an ingested report.
 */
class Dscc_Deep_Scan_Alert_Mailer_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
		FindingStore::reset();
		FrontendCatalogCache::rebuild();
		delete_option( DeepScanAlertMailer::OPTION );
		Settings::save_group(
			'scanner',
			array(
				'notify_violations' => '1',
				'notify_email'      => 'littled@example.test',
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		reset_phpmailer_instance();
		// The test host is "localhost": PHPMailer refuses wordpress@localhost as a sender.
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.test';
			}
		);
	}

	private function item( $name, $storage = 'cookie', $category = 'marketing', $service = '' ) {
		return array(
			'storage'  => $storage,
			'name'     => $name,
			'category' => $category,
			'service'  => $service,
			'domain'   => 'example.test',
		);
	}

	private function violations( array $before = array(), array $after = array() ) {
		return array(
			'before_choice' => $before,
			'after_reject'  => $after,
		);
	}

	private function sent_count() {
		$mailer = tests_retrieve_phpmailer_instance();
		return count( $mailer->mock_sent );
	}

	public function test_sends_once_with_site_phases_and_report_link_when_there_are_violations() {
		$v = $this->violations( array( $this->item( 'LittleD_fbp' ) ), array( $this->item( 'LittleD_ga', 'localStorage', 'analytics' ) ) );
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $v, true, '2026-11-08T10:00:00.000Z', 1000 ) );

		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'littled@example.test', $mail->to[0][0] );
		$this->assertStringContainsString( '2', $mail->subject );
		$this->assertStringContainsString( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $mail->subject );
		$this->assertStringContainsString( 'LittleD_fbp', $mail->body );
		$this->assertStringContainsString( 'LittleD_ga [localStorage]', $mail->body );
		$this->assertStringContainsString( 'page=dscc', $mail->body );
		$this->assertStringContainsString( 'conclusive', $mail->body );
		$this->assertStringContainsString( '08/11/2026', $mail->body );
	}

	public function test_an_inconclusive_scan_says_so() {
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $this->violations( array( $this->item( 'LittleD_x' ) ) ), false, '2026-11-08T10:00:00.000Z', 1000 ) );
		$this->assertStringContainsString( 'not conclusive', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	public function test_nothing_is_sent_without_violations() {
		$this->assertFalse( DeepScanAlertMailer::maybe_send( $this->violations(), true, '2026-11-08T10:00:00.000Z', 1000 ) );
		$this->assertSame( 0, $this->sent_count() );
	}

	public function test_the_same_set_is_not_resent_within_a_day_but_after_it() {
		$v = $this->violations( array( $this->item( 'LittleD_a' ), $this->item( 'LittleD_b' ) ) );
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $v, true, '2026-11-08T10:00:00.000Z', 1000 ) );
		$this->assertFalse( DeepScanAlertMailer::maybe_send( $this->violations( array_reverse( $v['before_choice'] ) ), true, '2026-11-08T11:00:00.000Z', 1000 + 3600 ), 'order does not matter' );
		$this->assertSame( 1, $this->sent_count() );
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $v, true, '2026-11-09T11:00:00.000Z', 1000 + 86400 + 1 ) );
		$this->assertSame( 2, $this->sent_count() );
	}

	public function test_a_changed_set_is_sent_at_once() {
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $this->violations( array( $this->item( 'LittleD_a' ) ) ), true, '2026-11-08T10:00:00.000Z', 1000 ) );
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $this->violations( array( $this->item( 'LittleD_a' ), $this->item( 'LittleD_c' ) ) ), true, '2026-11-08T10:05:00.000Z', 1060 ) );
		$this->assertSame( 2, $this->sent_count() );
	}

	public function test_a_conclusive_clean_scan_lets_a_returning_problem_be_reported_again() {
		$v = $this->violations( array( $this->item( 'LittleD_a' ) ) );
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $v, true, '2026-11-08T10:00:00.000Z', 1000 ) );
		DeepScanAlertMailer::maybe_send( $this->violations(), true, '2026-11-08T10:30:00.000Z', 2000 );
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $v, true, '2026-11-08T11:00:00.000Z', 3000 ) );
	}

	public function test_the_list_is_capped_with_a_remainder() {
		$items = array();
		for ( $i = 0; $i < DeepScanAlertMailer::MAX_LIST + 5; $i++ ) {
			$items[] = $this->item( 'LittleD_c' . $i );
		}
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $this->violations( $items ), true, '2026-11-08T10:00:00.000Z', 1000 ) );
		$body = tests_retrieve_phpmailer_instance()->get_sent()->body;
		$this->assertStringContainsString( 'LittleD_c' . ( DeepScanAlertMailer::MAX_LIST - 1 ), $body );
		$this->assertStringNotContainsString( 'LittleD_c' . DeepScanAlertMailer::MAX_LIST, $body );
		$this->assertStringContainsString( '... and 5 more.', $body );
	}

	public function test_the_toggle_switches_it_off_and_nothing_is_remembered() {
		Settings::save_group( 'scanner', array( 'notify_violations' => '' ) );
		$v = $this->violations( array( $this->item( 'LittleD_a' ) ) );
		$this->assertFalse( DeepScanAlertMailer::maybe_send( $v, true, '2026-11-08T10:00:00.000Z', 1000 ) );
		$this->assertSame( 0, $this->sent_count() );
		Settings::save_group( 'scanner', array( 'notify_violations' => '1' ) );
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $v, true, '2026-11-08T10:00:00.000Z', 1010 ) );
	}

	public function test_the_recipient_falls_back_to_the_admin_address() {
		Settings::save_group( 'scanner', array( 'notify_violations' => '1', 'notify_email' => '' ) );
		$this->assertTrue( DeepScanAlertMailer::maybe_send( $this->violations( array( $this->item( 'LittleD_a' ) ) ), true, '2026-11-08T10:00:00.000Z', 1000 ) );
		$this->assertSame( get_option( 'admin_email' ), tests_retrieve_phpmailer_instance()->get_sent()->to[0][0] );
	}

	public function test_an_ingested_deep_scan_with_a_violation_sends_the_email() {
		$host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$phase  = static function ( array $cookies = array() ) {
			return array(
				'pages'       => array( home_url( '/' ) ),
				'cookies'     => $cookies,
				'storage'     => array(
					'local'   => array(),
					'session' => array(),
				),
				'hosts'       => array(),
				'set_cookies' => array(),
			);
		};
		$cookie = array(
			'name'     => '_fbp',
			'domain'   => $host,
			'path'     => '/',
			'httpOnly' => false,
			'secure'   => false,
			'sameSite' => 'Lax',
			'expires'  => time() + 7776000,
		);
		$report = array(
			'schema'          => 1,
			'site'            => untrailingslashit( home_url() ),
			'scanned_at'      => gmdate( 'Y-m-d\TH:i:s.000\Z' ),
			'tool_version'    => '1.0.0',
			'plugin_detected' => true,
			'banner_found'    => true,
			'phases'          => array(
				'before_choice' => $phase( array( $cookie ) ),
				'after_reject'  => $phase(),
				'after_accept'  => $phase(),
			),
			'errors'          => array(),
		);
		$request = new WP_REST_Request( 'POST', '/dscc/v1/scan-result' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $report ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 1, $this->sent_count() );
		$this->assertStringContainsString( '_fbp', tests_retrieve_phpmailer_instance()->get_sent()->body );

		// The same report again within the day: stored again, mailed once only.
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( 1, $this->sent_count() );
	}
}
