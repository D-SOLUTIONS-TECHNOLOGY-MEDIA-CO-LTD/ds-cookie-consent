<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Catalog\PatternMatcher;
use PHPUnit\Framework\TestCase;

class PatternMatcherTest extends TestCase {

	public function test_exact_names_match_only_themselves_and_case_sensitively() {
		$this->assertTrue( PatternMatcher::name_matches( '_ga', '_ga' ) );
		$this->assertFalse( PatternMatcher::name_matches( '_ga', '_gat' ) );
		$this->assertFalse( PatternMatcher::name_matches( '_ga', '_GA' ) );
	}

	public function test_glob_patterns() {
		$this->assertTrue( PatternMatcher::name_matches( '_ga_*', '_ga_ABC123' ) );
		$this->assertTrue( PatternMatcher::name_matches( '_ga_*', '_ga_' ) );
		$this->assertFalse( PatternMatcher::name_matches( '_ga_*', '_ga' ) );
		$this->assertFalse( PatternMatcher::name_matches( '_ga_*', 'x_ga_ABC' ) );
		$this->assertTrue( PatternMatcher::name_matches( '_gat_UA-*', '_gat_UA-12345-1' ) );
		$this->assertTrue( PatternMatcher::name_matches( 'ttcsid*', 'ttcsid' ) );
		$this->assertTrue( PatternMatcher::name_matches( 'ttcsid*', 'ttcsid_C123' ) );
		$this->assertTrue( PatternMatcher::name_matches( 'intercom-id-*', 'intercom-id-abcd1234' ) );
	}

	public function test_dot_and_regex_characters_in_patterns_are_literal() {
		$this->assertTrue( PatternMatcher::name_matches( '_sp_id.*', '_sp_id.1a2b' ) );
		$this->assertFalse( PatternMatcher::name_matches( '_sp_id.*', '_sp_idX1a2b' ) );
		$this->assertTrue( PatternMatcher::name_matches( 'a+b(c)*', 'a+b(c)xyz' ) );
		$this->assertFalse( PatternMatcher::name_matches( 'a+b(c)*', 'aab(c)xyz' ) );
	}

	public function test_a_star_inside_the_name_matches_any_run() {
		$this->assertTrue( PatternMatcher::name_matches( 'wp_*_session', 'wp_woocommerce_session' ) );
		$this->assertFalse( PatternMatcher::name_matches( 'wp_*_session', 'wp_woocommerce_sessions' ) );
	}

	public function test_display_name_follows_the_policy_wording() {
		$this->assertSame( '_ga_<mã>', PatternMatcher::display_name( '_ga_*' ) );
		$this->assertSame( '_gat_UA-<mã>', PatternMatcher::display_name( '_gat_UA-*' ) );
		$this->assertSame( '_ga_<id>', PatternMatcher::display_name( '_ga_*', 'en' ) );
		$this->assertSame( 'ttcsid*', PatternMatcher::display_name( 'ttcsid*' ) );
		$this->assertSame( '_sp_id.*', PatternMatcher::display_name( '_sp_id.*' ) );
		$this->assertSame( '_gid', PatternMatcher::display_name( '_gid' ) );
	}

	public function test_host_matching_covers_subdomains_but_not_look_alikes() {
		$hosts = array( 'google-analytics.com', 'clarity.ms' );
		$this->assertTrue( PatternMatcher::host_matches( 'google-analytics.com', $hosts ) );
		$this->assertTrue( PatternMatcher::host_matches( 'www.google-analytics.com', $hosts ) );
		$this->assertFalse( PatternMatcher::host_matches( 'evilgoogle-analytics.com', $hosts ) );
		$this->assertFalse( PatternMatcher::host_matches( 'google-analytics.com.evil.example', $hosts ) );
		$this->assertFalse( PatternMatcher::host_matches( '', $hosts ) );
	}

	public function test_host_of_handles_protocol_relative_and_bad_urls() {
		$this->assertSame( 'www.clarity.ms', PatternMatcher::host_of( '//WWW.clarity.ms/tag/x' ) );
		$this->assertSame( 'a.example', PatternMatcher::host_of( 'https://a.example:8080/p?q=1' ) );
		$this->assertSame( '', PatternMatcher::host_of( 'not a url' ) );
	}

	public function test_script_matching_by_host_src_regex_and_inline() {
		$patterns = array(
			'hosts'        => array( 'clarity.ms' ),
			'src_regex'    => array( 'googletagmanager\.com/gtag/js\?id=G-' ),
			'inline_regex' => array( "fbq\\(\\s*'init'" ),
		);
		$this->assertTrue( PatternMatcher::script_matches( $patterns, 'https://www.clarity.ms/tag/abc' ) );
		$this->assertTrue( PatternMatcher::script_matches( $patterns, 'https://www.googletagmanager.com/gtag/js?id=G-LITTLED1' ) );
		$this->assertFalse( PatternMatcher::script_matches( $patterns, 'https://www.googletagmanager.com/gtm.js?id=GTM-1' ) );
		$this->assertTrue( PatternMatcher::script_matches( $patterns, '', "fbq( 'init', '1234' );" ) );
		$this->assertFalse( PatternMatcher::script_matches( $patterns, '', 'console.log(1)' ) );
		$this->assertFalse( PatternMatcher::script_matches( array(), 'https://x.example/a.js', 'x' ) );
	}

	public function test_regexes_are_case_insensitive_and_invalid_ones_never_match() {
		$this->assertTrue( PatternMatcher::script_matches( array( 'src_regex' => array( 'HOTJAR' ) ), 'https://static.hotjar.com/c/hotjar-1.js' ) );
		$this->assertFalse( PatternMatcher::valid_regex( '(unclosed' ) );
		$this->assertFalse( PatternMatcher::valid_regex( 'has~tilde' ) );
		$this->assertFalse( PatternMatcher::valid_regex( '' ) );
		$this->assertFalse( PatternMatcher::script_matches( array( 'src_regex' => array( '(unclosed' ) ), 'https://x.example/(unclosed' ) );
	}

	public function test_normalize_patterns_always_returns_three_string_lists() {
		$this->assertSame(
			array(
				'hosts'        => array( 'a.example' ),
				'src_regex'    => array(),
				'inline_regex' => array(),
			),
			PatternMatcher::normalize_patterns(
				array(
					'hosts'     => array( 'a.example', 5, null ),
					'src_regex' => 'oops',
					'extra'     => array( 'x' ),
				)
			)
		);
		$this->assertSame( array( 'hosts', 'src_regex', 'inline_regex' ), array_keys( PatternMatcher::normalize_patterns( null ) ) );
	}

	public function test_nested_quantifiers_are_refused() {
		foreach ( array( '(a+)+', '(\\s*x*)*', '(a*){2,}', '(.+)+$' ) as $regex ) {
			$this->assertTrue( PatternMatcher::has_nested_quantifier( $regex ), $regex );
			$this->assertFalse( PatternMatcher::valid_regex( $regex ), $regex );
		}
		foreach ( array( '(a|b)+', '(ab)+c', 'a+b*', 'googletagmanager\\.com/gtag/js\\?id=G-', "fbq\\(\\s*'init'" ) as $regex ) {
			$this->assertFalse( PatternMatcher::has_nested_quantifier( $regex ), $regex );
			$this->assertTrue( PatternMatcher::valid_regex( $regex ), $regex );
		}
	}

	public function test_display_name_only_turns_underscore_names_into_placeholders_and_marks_local_storage() {
		$this->assertSame( 'cly_*', PatternMatcher::display_name( 'cly_*' ) );
		$this->assertSame( 'sbjs_*', PatternMatcher::display_name( 'sbjs_*' ) );
		$this->assertSame( 'intercom-id-*', PatternMatcher::display_name( 'intercom-id-*' ) );
		$this->assertSame( '_hjSession_<mã>', PatternMatcher::display_name( '_hjSession_*' ) );
		$this->assertSame( 'cly_* (lưu cục bộ)', PatternMatcher::display_name( 'cly_*', 'vi', 'localStorage' ) );
		$this->assertSame( 'elementor (local storage)', PatternMatcher::display_name( 'elementor', 'en', 'localStorage' ) );
		$this->assertSame( 'Dữ liệu lưu cục bộ (phiên bàn)', PatternMatcher::display_name( 'Dữ liệu lưu cục bộ (phiên bàn)', 'vi', 'localStorage' ), 'sentences are not suffixed' );
	}
}
