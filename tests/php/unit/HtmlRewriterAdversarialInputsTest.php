<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Blocker\HtmlRewriter;
use DSolutions\CookieConsent\Catalog\PatternMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Markup the browser reads in a different way than a naive regex would: letter case, quoting, spacing, encoded
 * addresses, comments, non-JavaScript types and attributes that tell the blocker to stay away.
 */
class HtmlRewriterAdversarialInputsTest extends TestCase {

	private const GA_SRC = 'https://www.googletagmanager.com/gtag/js?id=G-LITTLED000';

	/**
	 * Rules for known-db services (same shape as the other rewriter tests).
	 *
	 * @param string[] $slugs Service slugs.
	 * @return array[]
	 */
	private function rules( array $slugs ) {
		$db    = json_decode( file_get_contents( dirname( __DIR__, 3 ) . '/data/known-cookies.json' ), true );
		$rules = array();
		foreach ( $db['services'] as $service ) {
			if ( ! in_array( $service['slug'], $slugs, true ) ) {
				continue;
			}
			$patterns = PatternMatcher::normalize_patterns( $service['script_patterns'] );
			$rules[]  = array(
				's' => $service['slug'],
				'c' => $service['category'],
				'h' => $patterns['hosts'],
				'r' => $patterns['src_regex'],
				'i' => $patterns['inline_regex'],
			);
		}
		return $rules;
	}

	private function blocked( $html, array $slugs = array( 'google-analytics' ), array $opts = array() ) {
		return HtmlRewriter::process( $html, $this->rules( $slugs ), $opts );
	}

	public function test_upper_case_tag_and_attribute_names_are_blocked() {
		$result = $this->blocked( '<SCRIPT SRC="' . self::GA_SRC . '"></SCRIPT>' );

		$this->assertStringContainsString( 'data-dscc-category="analytics" data-dscc-service="google-analytics"', $result['html'] );
		$this->assertStringContainsString( 'data-dscc-src="' . self::GA_SRC . '"', $result['html'] );
		$this->assertStringNotContainsString( ' SRC="', $result['html'] );
	}

	public function test_protocol_relative_and_padded_addresses_are_blocked() {
		$relative = $this->blocked( '<script src="//www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>' );
		$padded   = $this->blocked( '<script src=" ' . self::GA_SRC . ' "></script>' );

		$this->assertSame( 1, count( $relative['matches'] ) );
		$this->assertSame( 1, count( $padded['matches'] ) );
		$this->assertStringNotContainsString( ' src="//www.googletagmanager', $relative['html'] );
	}

	public function test_entity_encoded_address_is_decoded_before_matching() {
		$result = $this->blocked( '<script src="https&#58;//www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script>' );

		$this->assertSame( 1, count( $result['matches'] ) );
		$this->assertStringNotContainsString( ' src="', $result['html'] );
	}

	public function test_closing_tag_with_whitespace_still_ends_the_element() {
		$result = $this->blocked( "<script>gtag('config','G-LITTLED000');</script >\n<p>after</p>" );

		$this->assertStringContainsString( "data-no-optimize=\"1\">gtag('config','G-LITTLED000');</script >\n<p>after</p>", $result['html'] );
	}

	public function test_upper_case_iframe_gets_placeholder_and_hidden_src_only_once() {
		$result = $this->blocked( '<IFRAME SRC="https://www.youtube.com/embed/LittleD000" WIDTH="560"></IFRAME>', array( 'youtube' ), array( 'placeholder' => static function ( $info ) {
			return '<div class="dscc-placeholder" data-dscc-ph="' . $info['service'] . '"></div>';
		} ) );

		$this->assertSame( 1, substr_count( $result['html'], 'data-dscc-ph="youtube"' ) );
		$this->assertStringContainsString( '<iframe data-dscc-category="functional" data-dscc-service="youtube" hidden data-dscc-src="https://www.youtube.com/embed/LittleD000" WIDTH="560"></IFRAME>', $result['html'] );
	}

	public function test_pixel_hidden_with_an_inline_style_is_blocked_and_a_normal_image_is_not() {
		$pixel  = $this->blocked( '<img src="https://www.facebook.com/tr?id=000LittleD" style="width:1px;height:1px">', array( 'meta-pixel' ) );
		$photo  = $this->blocked( '<img src="https://www.facebook.com/tr?id=000LittleD" width="640" height="480">', array( 'meta-pixel' ) );

		$this->assertSame( 1, count( $pixel['matches'] ) );
		$this->assertSame( array(), $photo['matches'], 'a full-size image is content, not a tracker' );
	}

	public function test_iframe_with_ignore_attribute_and_quoted_greater_than_sign_are_handled() {
		$ignored = $this->blocked( '<iframe data-dscc-ignore src="https://www.youtube.com/embed/LittleD000"></iframe>', array( 'youtube' ) );
		$quoted  = $this->blocked( '<iframe title="a>b" src="https://www.youtube.com/embed/LittleD000"></iframe>', array( 'youtube' ) );

		$this->assertSame( array(), $ignored['matches'] );
		$this->assertStringContainsString( 'hidden title="a>b" data-dscc-src="https://www.youtube.com/embed/LittleD000"></iframe>', $quoted['html'] );
	}

	public function test_empty_src_script_is_left_alone_because_browsers_do_not_run_its_body() {
		$html   = '<script src="">gtag(\'config\',\'G-LITTLED000\');</script>';
		$result = $this->blocked( $html );

		$this->assertSame( $html, $result['html'] );
		$this->assertSame( array(), $result['matches'] );
	}

	public function test_commented_out_tags_are_not_rewritten() {
		$html   = '<!-- <script src="' . self::GA_SRC . '"></script> --><p>x</p>';
		$result = $this->blocked( $html );

		$this->assertSame( $html, $result['html'] );
		$this->assertSame( array(), $result['matches'] );
	}

	public function test_json_ld_and_template_content_naming_a_tracker_are_untouched() {
		$html   = '<script type="application/ld+json">{"sameAs":"' . self::GA_SRC . '"}</script>'
			. '<script type="text/template"><script src="' . self::GA_SRC . '"></script></script>';
		$result = $this->blocked( $html );

		$this->assertSame( $html, $result['html'] );
	}

	public function test_noscript_tracker_pixel_is_removed_because_a_visitor_without_scripts_cannot_consent() {
		$html   = '<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=000LittleD&ev=PageView&noscript=1"/></noscript>';
		$result = $this->blocked( $html, array( 'meta-pixel' ) );

		$this->assertSame( '<noscript></noscript>', $result['html'] );
		$this->assertSame( 'noscript', $result['matches'][0]['kind'] );
	}

	public function test_a_closing_tag_inside_a_javascript_string_does_not_hide_the_next_tracker() {
		$html   = '<script>var s="</script>";</script><script src="' . self::GA_SRC . '"></script>';
		$result = $this->blocked( $html );

		$this->assertStringContainsString( 'data-dscc-src="' . self::GA_SRC . '"', $result['html'] );
	}

	public function test_several_trackers_keep_their_document_order_in_the_output() {
		$html   = '<script src="' . self::GA_SRC . '"></script><p>mid</p><script>gtag(\'config\',\'G-LITTLED000\');</script>';
		$result = $this->blocked( $html );

		$this->assertSame( array( 'script', 'inline' ), array_column( $result['matches'], 'kind' ) );
		$this->assertLessThan( strpos( $result['html'], 'gtag' ), strpos( $result['html'], 'data-dscc-src' ) );
	}
}
