<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Consent\ConsentReader;
use DSolutions\CookieConsent\Settings\FieldValidators;
use PHPUnit\Framework\TestCase;

class ConsentReaderTest extends TestCase {

	private function raw( array $data ) {
		return json_encode( $data );
	}

	public function test_current_format_is_read_and_expiry_is_enforced() {
		$now = 1800000000;
		$rec = ConsentReader::parse( $this->raw( array( 'v' => '2026-11', 'id' => 'LittleD-1', 't' => $now - 100, 'c' => array( 'analytics' => 1, 'marketing' => 0 ), 's' => array( 'meta-pixel' => 1 ) ) ), '2026-11', 180, $now );
		$this->assertSame( 1, $rec['c']['analytics'] );
		$this->assertNull( ConsentReader::parse( $this->raw( array( 'v' => '2026-11', 'id' => 'LittleD-1', 't' => $now - 181 * 86400, 'c' => array( 'analytics' => 1 ) ) ), '2026-11', 180, $now ) );
		$this->assertNull( ConsentReader::parse( $this->raw( array( 'v' => '2025-01', 'id' => 'LittleD-1', 't' => $now - 1, 'c' => array( 'analytics' => 1 ) ) ), '2026-11', 180, $now ) );
	}

	public function test_current_format_is_normalised_and_double_encoded_value_is_read() {
		$now = 1800000000;
		$rec = ConsentReader::parse( $this->raw( array( 'v' => '2026-11', 'id' => 'LittleD-1234-abcd', 't' => $now - 50, 'c' => array( 'analytics' => 1, 'marketing' => '0' ), 's' => array( 'ga' => 1 ), 'src' => 'widget' ) ), '2026-11', 180, $now );
		$this->assertSame( array( 'analytics' => 1, 'marketing' => 0 ), $rec['c'] );
		$this->assertSame( array( 'ga' => 1 ), $rec['s'] );
		$this->assertSame( 'widget', $rec['src'] );

		$encoded = rawurlencode( $this->raw( array( 'v' => '2026-11', 'id' => 'LittleD-2', 't' => $now - 5, 'c' => array( 'marketing' => 1 ) ) ) );
		$this->assertSame( 1, ConsentReader::parse( $encoded, '2026-11', 180, $now )['c']['marketing'] );
	}

	public function test_old_two_flag_format_and_missing_id_are_rejected() {
		$now = 1800000000;
		$this->assertNull( ConsentReader::parse( $this->raw( array( 'v' => '2026-11', 'a' => true, 'm' => false, 't' => gmdate( 'c', $now - 50 ), 'id' => 'LittleD-old' ) ), '2026-11', 180, $now ) );
		$this->assertNull( ConsentReader::parse( $this->raw( array( 'v' => '2026-11', 't' => $now - 5, 'c' => array( 'analytics' => 1 ) ) ), '2026-11', 180, $now ) );
	}

	public function test_garbage_is_ignored() {
		foreach ( array( '', 'not json', '[]', '{"v":"2026-11"}', str_repeat( 'a', 5000 ), null ) as $raw ) {
			$this->assertNull( ConsentReader::parse( $raw, '2026-11', 180, 1800000000 ) );
		}
	}

	public function test_allows_follows_category_service_and_multi_category_rules() {
		$rec = array( 'c' => array( 'analytics' => 1, 'marketing' => 1 ), 's' => array( 'meta-pixel' => 0, 'youtube' => 1 ) );
		$this->assertTrue( ConsentReader::allows( null, 'necessary' ) );
		$this->assertFalse( ConsentReader::allows( null, 'analytics' ) );
		$this->assertTrue( ConsentReader::allows( $rec, 'analytics', 'google-analytics' ), 'a service without an entry follows its category' );
		$this->assertFalse( ConsentReader::allows( $rec, 'marketing', 'meta-pixel' ), 'its own switch is off' );
		$this->assertTrue( ConsentReader::allows( $rec, 'marketing', 'youtube' ) );
		$this->assertTrue( ConsentReader::allows( array( 'c' => array( 'analytics' => 0, 'marketing' => 1 ), 's' => array() ), 'analytics marketing', 'google-tag-manager' ), 'any part of a multi-category is enough' );
		$this->assertFalse( ConsentReader::allows( array( 'c' => array( 'analytics' => 0, 'marketing' => 0 ), 's' => array() ), 'analytics marketing' ) );
	}

	public function test_exclusion_entries_are_validated() {
		$this->assertTrue( FieldValidators::exclusion( 'my-tracker.js' ) );
		$this->assertTrue( FieldValidators::exclusion( '/gtag\/js\?id=G-\d+/' ) );
		$this->assertFalse( FieldValidators::exclusion( 'ab' ), 'too short to be a safe substring' );
		$this->assertFalse( FieldValidators::exclusion( '/(a+)+$/' ), 'nested quantifier' );
		$this->assertFalse( FieldValidators::exclusion( '/[unclosed/' ) );
		$this->assertFalse( FieldValidators::exclusion( '<script>' ) );
		$this->assertFalse( FieldValidators::exclusion( str_repeat( 'a', 201 ) ) );
	}
}
