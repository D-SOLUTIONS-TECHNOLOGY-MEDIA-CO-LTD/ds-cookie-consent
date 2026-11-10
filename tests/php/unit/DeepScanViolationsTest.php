<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Scanner\DeepScanValidator;
use DSolutions\CookieConsent\Scanner\DeepScanViolations;
use PHPUnit\Framework\TestCase;

/**
 * Violations: analytics / marketing present before a choice or after a rejection; accept phase is never checked.
 */
class DeepScanViolationsTest extends TestCase {

	private function result( $name, $category, $slug = '', $storage = 'cookie', $status = 'auto' ) {
		return array(
			'name'         => $name,
			'storage'      => $storage,
			'category'     => $category,
			'service_slug' => $slug,
			'status'       => $status,
		);
	}

	private function data( array $phases ) {
		$out = DeepScanValidator::validate( DeepScanFixtures::report( $phases ) );
		$this->assertTrue( $out['ok'], $out['message'] );
		return $out['data'];
	}

	public function test_analytics_and_marketing_before_choice_and_after_reject_are_violations() {
		$data = $this->data(
			array(
				'before_choice' => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( '_ga', 'localhost' ), DeepScanFixtures::cookie( 'LittleD_nec' ) ) ),
				'after_reject'  => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( '_fbp' ) ) ),
				'after_accept'  => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( '_clck' ) ) ),
			)
		);
		$results = array(
			$this->result( '_ga', 'analytics', 'google-analytics' ),
			$this->result( 'LittleD_nec', 'necessary' ),
			$this->result( '_fbp', 'marketing', 'meta-pixel' ),
			$this->result( '_clck', 'analytics', 'microsoft-clarity' ),
		);
		$v = DeepScanViolations::compute( $data, $results, array() );
		$this->assertSame( array( '_ga' ), array_column( $v['before_choice'], 'name' ) );
		$this->assertSame( array( '_fbp' ), array_column( $v['after_reject'], 'name' ) );
		$this->assertArrayNotHasKey( 'after_accept', $v );
		$this->assertSame( 'marketing', $v['after_reject'][0]['category'] );
		$this->assertSame( 'meta-pixel', $v['after_reject'][0]['service'] );
	}

	public function test_clean_phases_have_no_violations() {
		$data = $this->data( array( 'before_choice' => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( 'LittleD_nec' ) ) ) ) );
		$v    = DeepScanViolations::compute( $data, array( $this->result( 'LittleD_nec', 'necessary' ) ), array() );
		$this->assertSame( array(), $v['before_choice'] );
		$this->assertSame( array(), $v['after_reject'] );
	}

	public function test_unclassified_and_ignored_cookies_are_not_violations() {
		$data = $this->data( array( 'before_choice' => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( 'LittleD_u' ), DeepScanFixtures::cookie( '_fbp' ) ) ) ) );
		$v    = DeepScanViolations::compute(
			$data,
			array( $this->result( 'LittleD_u', 'unclassified', '', 'cookie', 'new' ), $this->result( '_fbp', 'marketing', 'meta-pixel', 'cookie', 'ignored' ) ),
			array()
		);
		$this->assertSame( array(), $v['before_choice'] );
	}

	public function test_vendor_hosts_are_violations_only_in_the_checked_phases() {
		$data    = $this->data(
			array(
				'before_choice' => DeepScanFixtures::phase( array(), array( 'localhost', 'www.clarity.ms' ) ),
				'after_accept'  => DeepScanFixtures::phase( array(), array( 'connect.facebook.net' ) ),
			)
		);
		$vendors = array(
			array( 'host' => 'www.clarity.ms', 'slug' => 'microsoft-clarity', 'category' => 'analytics' ),
			array( 'host' => 'connect.facebook.net', 'slug' => 'meta-pixel', 'category' => 'marketing' ),
		);
		$v = DeepScanViolations::compute( $data, array(), $vendors );
		$this->assertSame( array( 'www.clarity.ms' ), array_column( $v['before_choice'], 'name' ) );
		$this->assertSame( 'host', $v['before_choice'][0]['storage'] );
		$this->assertSame( array(), $v['after_reject'] );
	}

	public function test_web_storage_keys_count_and_are_listed_once_per_phase() {
		$report = DeepScanFixtures::report();
		$report['phases']['after_reject']['storage']['local'] = array( array( 'origin' => 'http://localhost:8888', 'key' => '_ga_store' ) );
		$report['phases']['after_reject']['cookies']          = array( DeepScanFixtures::cookie( '_ga' ), DeepScanFixtures::cookie( '_ga', '.localhost' ) );
		$data = DeepScanValidator::validate( $report )['data'];
		$v    = DeepScanViolations::compute(
			$data,
			array( $this->result( '_ga_store', 'analytics', 'google-analytics', 'localStorage' ), $this->result( '_ga', 'analytics', 'google-analytics' ) ),
			array()
		);
		$this->assertCount( 2, $v['after_reject'] );
		$this->assertSame( array( 'cookie', 'localStorage' ), array_column( $v['after_reject'], 'storage' ) );
	}

	public function test_a_google_host_without_a_google_cookie_is_a_consent_mode_warning() {
		$data = $this->data(
			array(
				'before_choice' => DeepScanFixtures::phase( array(), array( 'www.google-analytics.com', 'www.googleadservices.com', 'www.clarity.ms' ) ),
				'after_reject'  => DeepScanFixtures::phase( array(), array( 'www.google-analytics.com' ) ),
			)
		);
		$vendors = array(
			array( 'host' => 'www.google-analytics.com', 'slug' => 'google-analytics', 'category' => 'analytics' ),
			array( 'host' => 'www.googleadservices.com', 'slug' => 'google-ads', 'category' => 'marketing' ),
			array( 'host' => 'www.clarity.ms', 'slug' => 'microsoft-clarity', 'category' => 'analytics' ),
		);
		$split = DeepScanViolations::split_consent_mode_pings( DeepScanViolations::compute( $data, array(), $vendors ) );
		$this->assertSame( array( 'www.clarity.ms' ), array_column( $split['violations']['before_choice'], 'name' ), 'a non-Google vendor host stays a violation' );
		$this->assertSame( array( 'www.google-analytics.com', 'www.googleadservices.com' ), array_column( $split['warnings']['before_choice'], 'name' ) );
		$this->assertSame( array(), $split['violations']['after_reject'] );
		$this->assertSame( array( 'www.google-analytics.com' ), array_column( $split['warnings']['after_reject'], 'name' ) );
	}

	public function test_a_google_host_next_to_a_google_cookie_stays_a_violation() {
		$data    = $this->data( array( 'before_choice' => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( '_ga' ) ), array( 'www.google-analytics.com', 'www.googleadservices.com' ) ) ) );
		$vendors = array(
			array( 'host' => 'www.google-analytics.com', 'slug' => 'google-analytics', 'category' => 'analytics' ),
			array( 'host' => 'www.googleadservices.com', 'slug' => 'google-ads', 'category' => 'marketing' ),
		);
		$split = DeepScanViolations::split_consent_mode_pings( DeepScanViolations::compute( $data, array( $this->result( '_ga', 'analytics', 'google-analytics' ) ), $vendors ) );
		$this->assertSame( array( '_ga', 'www.google-analytics.com' ), array_column( $split['violations']['before_choice'], 'name' ), 'the Google tag set its cookie: not a cookieless ping' );
		$this->assertSame( array( 'www.googleadservices.com' ), array_column( $split['warnings']['before_choice'], 'name' ), 'Google Ads set nothing in this phase' );
	}

	public function test_the_ping_service_list_can_be_narrowed() {
		$data  = $this->data( array( 'before_choice' => DeepScanFixtures::phase( array(), array( 'www.google-analytics.com' ) ) ) );
		$v     = DeepScanViolations::compute( $data, array(), array( array( 'host' => 'www.google-analytics.com', 'slug' => 'google-analytics', 'category' => 'analytics' ) ) );
		$split = DeepScanViolations::split_consent_mode_pings( $v, array() );
		$this->assertSame( array( 'www.google-analytics.com' ), array_column( $split['violations']['before_choice'], 'name' ) );
		$this->assertSame( array(), $split['warnings']['before_choice'] );
	}
}
