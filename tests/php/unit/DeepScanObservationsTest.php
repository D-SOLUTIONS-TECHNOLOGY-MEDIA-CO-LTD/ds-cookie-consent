<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Scanner\DeepScanObservations;
use DSolutions\CookieConsent\Scanner\DeepScanValidator;
use PHPUnit\Framework\TestCase;

/**
 * Observations handed to the catalog merge: own vs third-party, server vs client, no personal names.
 */
class DeepScanObservationsTest extends TestCase {

	private function build( array $phases ) {
		$out = DeepScanValidator::validate( DeepScanFixtures::report( $phases ) );
		$this->assertTrue( $out['ok'], $out['message'] );
		$built = DeepScanObservations::build( $out['data'], array( 'localhost' ) );
		$by    = array();
		foreach ( $built['observations'] as $obs ) {
			$by[ $obs['storage'] . '|' . $obs['name'] ] = $obs;
		}
		return array( $by, $built['skipped'] );
	}

	public function test_own_domain_matching() {
		$own = array( 'example.test', 'www.example.test' );
		$this->assertTrue( DeepScanObservations::is_own_domain( 'example.test', $own ) );
		$this->assertTrue( DeepScanObservations::is_own_domain( '.example.test', $own ) );
		$this->assertTrue( DeepScanObservations::is_own_domain( 'shop.example.test', $own ), 'sub domain' );
		$this->assertFalse( DeepScanObservations::is_own_domain( 'notexample.test', $own ) );
		$this->assertFalse( DeepScanObservations::is_own_domain( 'doubleclick.net', $own ) );
		$this->assertFalse( DeepScanObservations::is_own_domain( '', $own ) );
	}

	public function test_own_cookie_with_set_cookie_evidence_is_server_otherwise_client() {
		list( $by ) = $this->build(
			array(
				'before_choice' => DeepScanFixtures::phase(
					array( DeepScanFixtures::cookie( 'LittleD_srv' ), DeepScanFixtures::cookie( 'LittleD_js' ) ),
					array( 'localhost' ),
					array( array( 'host' => 'localhost', 'names' => array( 'LittleD_srv' ) ) )
				),
			)
		);
		$this->assertSame( 'server', $by['cookie|LittleD_srv']['set_by'] );
		$this->assertSame( 'client', $by['cookie|LittleD_js']['set_by'] );
	}

	public function test_third_party_cookies_are_unknown_and_set_cookie_of_other_hosts_does_not_count() {
		list( $by ) = $this->build(
			array(
				'after_accept' => DeepScanFixtures::phase(
					array( DeepScanFixtures::cookie( 'LittleD_ad', '.ads.example' ) ),
					array(),
					array( array( 'host' => 'ads.example', 'names' => array( 'LittleD_ad' ) ) )
				),
			)
		);
		$this->assertSame( 'unknown', $by['cookie|LittleD_ad']['set_by'] );
		$this->assertSame( 'ads.example', $by['cookie|LittleD_ad']['domain'] );
	}

	public function test_the_most_certain_source_wins_across_phases_and_lifetime_is_relative_to_scan_time() {
		$scan = DeepScanFixtures::scanned_at();
		list( $by ) = $this->build(
			array(
				'before_choice' => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( 'LittleD_x', 'localhost', $scan + 86400 ) ), array(), array( array( 'host' => 'localhost', 'names' => array( 'LittleD_x' ) ) ) ),
				'after_accept'  => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( 'LittleD_x', 'localhost', -1 ) ) ),
			)
		);
		$this->assertSame( 'server', $by['cookie|LittleD_x']['set_by'] );
		$this->assertSame( 86400, $by['cookie|LittleD_x']['max_age'] );
		$this->assertTrue( $by['cookie|LittleD_x']['has_lifetime'] );
	}

	public function test_session_cookie_has_no_max_age() {
		list( $by ) = $this->build( array( 'after_accept' => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( 'LittleD_sess', 'localhost', -1 ) ) ) ) );
		$this->assertNull( $by['cookie|LittleD_sess']['max_age'] );
		$this->assertTrue( $by['cookie|LittleD_sess']['has_lifetime'] );
	}

	public function test_wordpress_admin_cookies_and_non_literal_names_are_skipped() {
		list( $by, $skipped ) = $this->build(
			array(
				'after_accept' => DeepScanFixtures::phase(
					array(
						DeepScanFixtures::cookie( 'wordpress_logged_in_abc' ),
						DeepScanFixtures::cookie( 'LittleD_*' ),
						DeepScanFixtures::cookie( 'a b' ),
						DeepScanFixtures::cookie( 'LittleD_ok' ),
					)
				),
			)
		);
		$this->assertSame( array( 'cookie|LittleD_ok' ), array_keys( $by ) );
		$this->assertSame( 3, $skipped );
	}

	public function test_names_that_look_personal_are_dropped_for_cookies_and_storage() {
		$report = DeepScanFixtures::report( array( 'after_accept' => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( 'LittleD_12345678' ), DeepScanFixtures::cookie( 'user@littled.test' ), DeepScanFixtures::cookie( 'LittleD_ok' ) ) ) ) );
		$report['phases']['after_accept']['storage']['local'] = array( array( 'origin' => 'http://localhost:8888', 'key' => 'LittleD_0123456789abcdef0' ) );
		$built = DeepScanObservations::build( DeepScanValidator::validate( $report )['data'], array( 'localhost' ) );
		$this->assertSame( array( 'LittleD_ok' ), array_column( $built['observations'], 'name' ) );
		$this->assertSame( 3, $built['skipped'] );
	}

	public function test_only_own_origin_storage_keys_are_observed() {
		$report = DeepScanFixtures::report();
		$report['phases']['after_accept']['storage']['local']   = array(
			array( 'origin' => 'http://localhost:8888', 'key' => 'LittleD_own' ),
			array( 'origin' => 'https://frame.example', 'key' => 'LittleD_frame' ),
		);
		$report['phases']['after_accept']['storage']['session'] = array( array( 'origin' => 'http://localhost:8888', 'key' => 'LittleD_sess' ) );
		$out   = DeepScanValidator::validate( $report );
		$built = DeepScanObservations::build( $out['data'], array( 'localhost' ) );
		$names = array_column( $built['observations'], 'storage', 'name' );
		$this->assertSame(
			array(
				'LittleD_own'  => 'localStorage',
				'LittleD_sess' => 'sessionStorage',
			),
			$names
		);
	}
}
