<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Settings\ContrastChecker;
use PHPUnit\Framework\TestCase;

class ContrastCheckerTest extends TestCase {

	public function test_default_colours_pass() {
		$this->assertSame( array(), ContrastChecker::failures( array( 'color_primary' => '#005ea3', 'color_text' => '#1e3a5f', 'color_bar_bg' => '#f1f5f9' ) ) );
	}

	public function test_low_contrast_reported_with_ratio() {
		$f = ContrastChecker::failures( array( 'color_primary' => '#7fbfef', 'color_text' => '#aaaaaa', 'color_bar_bg' => '#f1f5f9' ) );
		$this->assertArrayHasKey( 'primary_on_white', $f );
		$this->assertArrayHasKey( 'text_on_bar', $f );
		$this->assertLessThan( 4.5, $f['primary_on_white'] );
	}

	public function test_invalid_colours_are_skipped() {
		$this->assertSame( array(), ContrastChecker::failures( array( 'color_primary' => 'nope', 'color_text' => '', 'color_bar_bg' => '' ) ) );
	}
}
