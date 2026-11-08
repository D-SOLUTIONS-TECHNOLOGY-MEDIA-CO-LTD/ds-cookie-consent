<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use Brain\Monkey\Functions;
use DSolutions\CookieConsent\Support\LinkHelper;

class LinkHelperTest extends UnitTestCase {

	protected function set_up() {
		parent::set_up();
		Functions\stubs(
			array(
				'esc_url'  => static function ( $s ) {
					return htmlspecialchars( (string) $s, ENT_QUOTES );
				},
				'esc_html' => static function ( $s ) {
					return htmlspecialchars( (string) $s, ENT_QUOTES );
				},
			)
		);
	}

	public function test_link_has_blank_target_and_full_rel() {
		$html = LinkHelper::external_policy_link( 'https://policies.example/privacy', 'LittleD Analytics' );
		$this->assertStringContainsString( 'href="https://policies.example/privacy"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="nofollow noopener noreferrer"', $html );
		$this->assertStringContainsString( '>LittleD Analytics</a>', $html );
	}

	public function test_label_is_escaped() {
		$html = LinkHelper::external_policy_link( 'https://policies.example/p', '<b>x</b>' );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_non_http_urls_yield_nothing() {
		$this->assertSame( '', LinkHelper::external_policy_link( 'javascript:alert(1)', 'x' ) );
		$this->assertSame( '', LinkHelper::external_policy_link( '/relative', 'x' ) );
		$this->assertSame( '', LinkHelper::external_policy_link( '', 'x' ) );
	}
}
