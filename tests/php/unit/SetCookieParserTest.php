<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Scanner\SetCookieParser;
use PHPUnit\Framework\TestCase;

/**
 * Set-Cookie header parsing: attributes are kept, the value never is.
 */
class SetCookieParserTest extends TestCase {

	private const NOW = 1700000000;

	public function test_reads_name_domain_path_max_age_and_flags() {
		$c = SetCookieParser::parse( '_fbp=fb.1.1700000000.LittleD; Max-Age=7776000; Domain=.Example.Test; Path=/shop; Secure; HttpOnly; SameSite=Lax', self::NOW );
		$this->assertSame( '_fbp', $c['name'] );
		$this->assertSame( '.example.test', $c['domain'] );
		$this->assertSame( '/shop', $c['path'] );
		$this->assertSame( 7776000, $c['max_age'] );
		$this->assertTrue( $c['http_only'] );
		$this->assertTrue( $c['secure'] );
		$this->assertSame( 'lax', $c['same_site'] );
		$this->assertFalse( $c['deleted'] );
		$this->assertArrayNotHasKey( 'value', $c );
		$this->assertStringNotContainsString( 'LittleD', json_encode( $c ) );
	}

	public function test_session_cookie_has_no_max_age() {
		$c = SetCookieParser::parse( 'wp-wpml_current_language=vi; path=/', self::NOW );
		$this->assertNull( $c['max_age'] );
		$this->assertSame( '', $c['domain'] );
		$this->assertFalse( $c['http_only'] );
	}

	public function test_expires_becomes_seconds_from_now() {
		$c = SetCookieParser::parse( 'a=1; Expires=' . gmdate( 'D, d M Y H:i:s', self::NOW + 86400 ) . ' GMT', self::NOW );
		$this->assertSame( 86400, $c['max_age'] );
	}

	public function test_max_age_wins_over_expires() {
		$c = SetCookieParser::parse( 'a=1; Max-Age=60; Expires=' . gmdate( 'D, d M Y H:i:s', self::NOW + 86400 ) . ' GMT', self::NOW );
		$this->assertSame( 60, $c['max_age'] );
	}

	public function test_a_deletion_header_is_flagged() {
		$this->assertTrue( SetCookieParser::parse( 'a=deleted; Max-Age=0; Path=/', self::NOW )['deleted'] );
		$this->assertTrue( SetCookieParser::parse( 'a=deleted; Expires=Thu, 01 Jan 1970 00:00:01 GMT', self::NOW )['deleted'] );
	}

	public function test_prefix_is_accepted_and_names_are_decoded() {
		$c = SetCookieParser::parse( 'Set-Cookie: littled%5Fname=1', self::NOW );
		$this->assertSame( 'littled_name', $c['name'] );
	}

	public function test_unusable_headers_return_null() {
		foreach ( array( '', 'novalue', '=value', 'bad name=1', "x\x00y=1", str_repeat( 'a', 101 ) . '=1', 'a<b=1' ) as $header ) {
			$this->assertNull( SetCookieParser::parse( $header, self::NOW ), $header );
		}
	}
}
