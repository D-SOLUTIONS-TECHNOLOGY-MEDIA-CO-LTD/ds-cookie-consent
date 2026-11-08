<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Settings\ColorUtils;
use PHPUnit\Framework\TestCase;

class ColorUtilsTest extends TestCase {

	public function test_normalize_hex() {
		$this->assertSame( '#005ea3', ColorUtils::normalize_hex( '#005EA3' ) );
		$this->assertSame( '#aabbcc', ColorUtils::normalize_hex( '#ABC' ) );
		$this->assertNull( ColorUtils::normalize_hex( 'red' ) );
		$this->assertNull( ColorUtils::normalize_hex( '#12345' ) );
		$this->assertNull( ColorUtils::normalize_hex( array() ) );
	}

	public function test_contrast_extremes() {
		$this->assertEqualsWithDelta( 21.0, ColorUtils::contrast( '#000000', '#ffffff' ), 0.01 );
		$this->assertEqualsWithDelta( 1.0, ColorUtils::contrast( '#777777', '#777777' ), 0.001 );
	}

	public function test_default_primary_passes_aa_with_white_text() {
		$this->assertGreaterThan( 4.5, ColorUtils::contrast( '#ffffff', '#005ea3' ) );
		$this->assertLessThan( 4.5, ColorUtils::contrast( '#ffffff', '#7fbfef' ) );
	}

	public function test_darken_12_percent() {
		// Every channel scaled by 0.88: 0x5e=94 -> 83 (0x53), 0xa3=163 -> 143 (0x8f).
		$this->assertSame( '#00538f', ColorUtils::darken( '#005ea3' ) );
		$this->assertSame( '#000000', ColorUtils::darken( '#000000' ) );
	}

	public function test_tint_on_white_12_percent() {
		// 12% of the colour on white: 255*0.88=224.4 -> e0, 94*.12+224.4=235.68 -> ec, 163*.12+224.4=243.96 -> f4.
		$this->assertSame( '#e0ecf4', ColorUtils::tint_on_white( '#005ea3' ) );
		$this->assertSame( '#ffffff', ColorUtils::tint_on_white( '#ffffff' ) );
	}
}
