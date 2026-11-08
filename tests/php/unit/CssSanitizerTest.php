<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Settings\CssSanitizer;
use PHPUnit\Framework\TestCase;

class CssSanitizerTest extends TestCase {

	public function test_blocks_style_breakout_and_active_content() {
		$css = ".a{color:red}</style><script>alert(1)</script>\n@import url(http://evil.example/x.css);\n.b{background:url( 'javascript:alert(1)' )}\n.c{width:expression(alert(1))}";
		$out = CssSanitizer::sanitize( $css );
		$this->assertStringNotContainsStringIgnoringCase( '</style', $out );
		$this->assertStringNotContainsString( '<', $out );
		$this->assertStringNotContainsStringIgnoringCase( '@import', $out );
		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $out );
		$this->assertStringNotContainsStringIgnoringCase( 'expression(', $out );
		$this->assertStringContainsString( '.a{color:red}', $out );
	}

	public function test_comment_cannot_split_blocked_token() {
		$out = CssSanitizer::sanitize( '@im/**/port url(x);' );
		$this->assertStringNotContainsStringIgnoringCase( '@import', $out );
	}

	public function test_unbalanced_braces_rejected() {
		$this->assertSame( '', CssSanitizer::sanitize( '.a{color:red' ) );
	}

	public function test_font_face_dropped() {
		$this->assertStringNotContainsString( 'font-face', CssSanitizer::sanitize( '@font-face{font-family:x;src:url(a.woff)} .a{color:red}' ) );
	}

	public function test_scope_prefixes_selectors() {
		$out = CssSanitizer::scope( '.dscc-btn, a:is(.x,.y){color:red} @media (max-width:600px){.p{margin:0}} #dscc .z{top:0}' );
		$this->assertStringContainsString( '#dscc .dscc-btn,#dscc a:is(.x,.y){color:red}', $out );
		$this->assertStringContainsString( '@media (max-width:600px){#dscc .p{margin:0}}', $out );
		$this->assertStringContainsString( '#dscc .z{top:0}', $out );
		$this->assertStringNotContainsString( '##dscc', $out );
	}

	public function test_check_reports_why_a_stylesheet_is_refused() {
		$this->assertSame( 'unbalanced', CssSanitizer::check( '.a{color:red' )['error'] );
		$this->assertSame( 'unbalanced', CssSanitizer::check( '.a{color:red}}' )['error'] );
		$this->assertSame( 'too_long', CssSanitizer::check( str_repeat( 'a', CssSanitizer::MAX_LENGTH + 1 ) )['error'] );
		$ok = CssSanitizer::check( '.a{color:red}' );
		$this->assertSame( '', $ok['error'] );
		$this->assertSame( '.a{color:red}', $ok['css'] );
		$this->assertSame( array( 'css' => '', 'error' => '' ), CssSanitizer::check( '' ) );
	}

	public function test_scope_wraps_top_level_declarations_in_one_root_rule() {
		$out = CssSanitizer::scope( 'color:red; --dscc-radius:4px; .a{top:0} margin:0;' );
		$this->assertSame( '#dscc{color:red;--dscc-radius:4px;margin:0;}#dscc .a{top:0}', $out );
		$this->assertSame( '#dscc{color:red;}', CssSanitizer::scope( 'color:red;' ) );
	}

	public function test_scope_drops_statement_at_rules_and_junk_before_a_selector() {
		$out = CssSanitizer::scope( '@layer a, b; .a{top:0}' );
		$this->assertSame( '#dscc .a{top:0}', $out );
	}

	public function test_scope_empty() {
		$this->assertSame( '', CssSanitizer::scope( '' ) );
	}
}
