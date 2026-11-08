<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Settings\DefaultTexts;
use PHPUnit\Framework\TestCase;

class DefaultTextsTest extends TestCase {

	public function test_vi_and_en_have_identical_keys() {
		$vi = array_keys( DefaultTexts::all( 'vi' ) );
		$en = array_keys( DefaultTexts::all( 'en' ) );
		sort( $vi );
		sort( $en );
		$this->assertSame( $vi, $en );
	}

	public function test_no_empty_values_and_no_brand_leak() {
		foreach ( DefaultTexts::LANGS as $lang ) {
			foreach ( DefaultTexts::all( $lang ) as $key => $value ) {
				$this->assertNotSame( '', trim( $value ), "$lang.$key is empty" );
				$this->assertStringNotContainsStringIgnoringCase( 'ip' . 'os', $value, "$lang.$key mentions a client brand" );
			}
		}
	}

	public function test_tokens_match_between_languages() {
		$vi = DefaultTexts::all( 'vi' );
		$en = DefaultTexts::all( 'en' );
		foreach ( $vi as $key => $value ) {
			preg_match_all( '/\{[a-z_]+\}/', $value, $a );
			preg_match_all( '/\{[a-z_]+\}/', $en[ $key ], $b );
			$this->assertSame( $a[0], $b[0], "token mismatch in $key" );
		}
	}

	public function test_merged_applies_non_empty_overrides_only() {
		$m = DefaultTexts::merged( 'vi', array( 'accept' => 'LittleD OK', 'reject' => '  ', 'nope' => 'x' ) );
		$this->assertSame( 'LittleD OK', $m['accept'] );
		$this->assertSame( 'Từ chối', $m['reject'] );
		$this->assertArrayNotHasKey( 'nope', $m );
	}

	public function test_unknown_language_falls_back_to_vi() {
		$this->assertSame( DefaultTexts::all( 'vi' ), DefaultTexts::all( 'fr' ) );
	}
}
