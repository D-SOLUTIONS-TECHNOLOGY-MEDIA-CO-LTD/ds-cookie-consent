<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Scanner\DeepScanValidator;
use PHPUnit\Framework\TestCase;

/**
 * The deep scan payload is validated strictly: shape, types, limits, and never a cookie value.
 */
class DeepScanValidatorTest extends TestCase {

	public function test_a_report_in_the_tool_shape_is_accepted_and_normalised() {
		$report = DeepScanFixtures::report(
			array(
				'before_choice' => DeepScanFixtures::phase(
					array( DeepScanFixtures::cookie( 'LittleD_a', '.Localhost', 1900000000.5 ) ),
					array( 'localhost', 'www.clarity.ms' ),
					array( array( 'host' => 'localhost', 'names' => array( 'LittleD_a' ) ) )
				),
			)
		);
		$report['phases']['after_accept']['storage']['local'] = array( array( 'origin' => 'http://localhost:8888', 'key' => 'LittleD_k' ) );
		$out = DeepScanValidator::validate( $report );
		$this->assertTrue( $out['ok'], $out['message'] );
		$cookie = $out['data']['phases']['before_choice']['cookies'][0];
		$this->assertSame( '.localhost', $cookie['domain'] );
		$this->assertSame( 1900000000, $cookie['expires'] );
		$this->assertSame( 'http://localhost:8888', $out['data']['site'] );
		$this->assertSame( 'LittleD_k', $out['data']['phases']['after_accept']['local'][0]['key'] );
		$this->assertSame( DeepScanFixtures::scanned_at(), $out['data']['scanned_at'] );
	}

	public function test_session_cookie_expiry_minus_one_is_valid() {
		$report = DeepScanFixtures::report( array( 'after_accept' => DeepScanFixtures::phase( array( DeepScanFixtures::cookie( 'LittleD_s', 'localhost', -1 ) ) ) ) );
		$this->assertTrue( DeepScanValidator::validate( $report )['ok'] );
	}

	public function test_unknown_schema_is_rejected() {
		foreach ( array( 2, '1', 0, null ) as $schema ) {
			$report           = DeepScanFixtures::report();
			$report['schema'] = $schema;
			$out              = DeepScanValidator::validate( $report );
			$this->assertFalse( $out['ok'] );
			$this->assertSame( 'unsupported_schema', $out['error'] );
		}
	}

	public function test_a_cookie_value_is_never_accepted() {
		$cookie          = DeepScanFixtures::cookie( 'LittleD_a' );
		$cookie['value'] = 'LittleDSecret';
		$out             = DeepScanValidator::validate( DeepScanFixtures::report( array( 'before_choice' => DeepScanFixtures::phase( array( $cookie ) ) ) ) );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'unknown_field', $out['error'] );
		$this->assertStringContainsString( 'value', $out['message'] );
	}

	public function test_unknown_keys_anywhere_are_rejected() {
		$top          = DeepScanFixtures::report();
		$top['extra'] = 1;
		$this->assertSame( 'unknown_field', DeepScanValidator::validate( $top )['error'] );
		$phase          = DeepScanFixtures::phase();
		$phase['dump']  = array();
		$this->assertFalse( DeepScanValidator::validate( DeepScanFixtures::report( array( 'after_reject' => $phase ) ) )['ok'] );
	}

	public function test_missing_phase_or_bad_types_are_rejected() {
		$report = DeepScanFixtures::report();
		unset( $report['phases']['after_reject'] );
		$this->assertFalse( DeepScanValidator::validate( $report )['ok'] );

		foreach ( array(
			array( 'site', 'ftp://x.test' ),
			array( 'site', 'localhost' ),
			array( 'scanned_at', 'not a date' ),
			array( 'banner_found', 'yes' ),
			array( 'errors', 'boom' ),
			array( 'tool_version', str_repeat( 'x', 40 ) ),
		) as $case ) {
			$bad             = DeepScanFixtures::report();
			$bad[ $case[0] ] = $case[1];
			$this->assertFalse( DeepScanValidator::validate( $bad )['ok'], $case[0] );
		}
	}

	public function test_cookie_fields_are_type_checked() {
		foreach ( array(
			array( 'name', '' ),
			array( 'name', array( 'a' ) ),
			array( 'domain', '<script>' ),
			array( 'httpOnly', 'true' ),
			array( 'sameSite', 'Maybe' ),
			array( 'expires', '123' ),
			array( 'expires', -5 ),
		) as $case ) {
			$cookie             = DeepScanFixtures::cookie( 'LittleD_a' );
			$cookie[ $case[0] ] = $case[1];
			$out                = DeepScanValidator::validate( DeepScanFixtures::report( array( 'before_choice' => DeepScanFixtures::phase( array( $cookie ) ) ) ) );
			$this->assertFalse( $out['ok'], $case[0] . '=' . wp_json_encode_stub( $case[1] ) );
		}
	}

	public function test_storage_entries_must_be_origin_key_objects() {
		$report = DeepScanFixtures::report();
		$report['phases']['before_choice']['storage']['local'] = array( 'LittleD_k' );
		$this->assertFalse( DeepScanValidator::validate( $report )['ok'] );
	}

	public function test_limits_are_enforced() {
		$many = array();
		for ( $i = 0; $i <= DeepScanValidator::MAX_COOKIES; $i++ ) {
			$many[] = DeepScanFixtures::cookie( 'LittleD_' . $i );
		}
		$this->assertFalse( DeepScanValidator::validate( DeepScanFixtures::report( array( 'before_choice' => DeepScanFixtures::phase( $many ) ) ) )['ok'] );
	}

	public function test_the_first_phase_must_have_loaded_a_page() {
		$out = DeepScanValidator::validate( DeepScanFixtures::report( array( 'before_choice' => array_merge( DeepScanFixtures::phase(), array( 'pages' => array() ) ) ) ) );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'bad_field', $out['error'] );
		$this->assertStringContainsString( 'before_choice.pages', $out['message'] );
		// A later phase without pages is accepted (the scan is then reported as inconclusive).
		$this->assertTrue( DeepScanValidator::validate( DeepScanFixtures::report( array( 'after_reject' => array_merge( DeepScanFixtures::phase(), array( 'pages' => array() ) ) ) ) )['ok'] );
	}

	public function test_scanned_at_must_be_iso_8601_recent_and_not_from_the_future() {
		$now = 1900000000;
		foreach ( array(
			'2030-03-17 17:46:40',
			'yesterday',
			'1900000000',
			'2030-03-17T17:46:40',
			gmdate( 'Y-m-d\TH:i:s\Z', $now + 601 ),
			gmdate( 'Y-m-d\TH:i:s\Z', $now - 2592001 ),
			array( 'x' ),
		) as $value ) {
			$report               = DeepScanFixtures::report();
			$report['scanned_at'] = $value;
			$out                  = DeepScanValidator::validate( $report, $now );
			$this->assertFalse( $out['ok'], is_array( $value ) ? 'array' : $value );
			$this->assertSame( 'scanned_at', $out['message'] );
		}
		foreach ( array(
			gmdate( 'Y-m-d\TH:i:s\Z', $now + 600 ),
			gmdate( 'Y-m-d\TH:i:s.123\Z', $now - 2592000 ),
			gmdate( 'Y-m-d\TH:i:s', $now ) . '+07:00',
		) as $value ) {
			$report               = DeepScanFixtures::report();
			$report['scanned_at'] = $value;
			$this->assertTrue( DeepScanValidator::validate( $report, $now )['ok'], $value );
		}
	}

	public function test_non_object_bodies_are_rejected() {
		foreach ( array( null, 'text', 5, array( 1, 2 ) ) as $body ) {
			$this->assertSame( 'bad_payload', DeepScanValidator::validate( $body )['error'] );
		}
	}
}

/**
 * Message helper for data-driven assertions.
 *
 * @param mixed $v Value.
 * @return string
 */
function wp_json_encode_stub( $v ) {
	return json_encode( $v );
}
