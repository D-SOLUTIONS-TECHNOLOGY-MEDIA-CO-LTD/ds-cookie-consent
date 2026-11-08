<?php
use DSolutions\CookieConsent\Frontend\EmbedMode;
use DSolutions\CookieConsent\Frontend\Renderer;

/**
 * Where the banner and head script may appear: front pages only. Feeds, previews and filtered
 * requests get no banner; the ?dscc_embed=1 policy view gets the head script but no banner and is noindex.
 */
class Dscc_Frontend_Context_Test extends WP_UnitTestCase {

	public function tear_down() {
		unset( $_GET['dscc_embed'], $_GET['dscc_preview'] );
		remove_all_filters( 'dscc_should_render' );
		parent::tear_down();
	}

	public function test_front_page_prints_banner_and_head() {
		$this->go_to( home_url( '/' ) );
		$this->assertTrue( Renderer::should_print_banner() );
		$this->assertTrue( Renderer::should_print_head() );
	}

	public function test_feed_request_prints_neither_banner_nor_head() {
		$this->go_to( home_url( '/?feed=rss2' ) );
		$this->assertFalse( Renderer::should_print_banner() );
		$this->assertFalse( Renderer::should_print_head() );
	}

	public function test_embedded_policy_view_keeps_head_but_no_banner() {
		$_GET['dscc_embed'] = '1';
		$this->go_to( home_url( '/?dscc_embed=1' ) );
		$this->assertFalse( Renderer::should_print_banner() );
		$this->assertTrue( Renderer::should_print_head(), 'tags inside the embedded view must stay denied through the Consent Mode defaults' );
	}

	public function test_builder_preview_and_filter_suppress_banner() {
		$_GET['dscc_preview'] = '1';
		$this->go_to( home_url( '/?dscc_preview=1' ) );
		$this->assertFalse( Renderer::should_print_banner() );

		unset( $_GET['dscc_preview'] );
		add_filter( 'dscc_should_render', '__return_false' );
		$this->go_to( home_url( '/' ) );
		$this->assertFalse( Renderer::should_print_banner() );
	}

	public function test_embed_view_is_noindex_nofollow_and_gets_theme_hiding_css() {
		$_GET['dscc_embed'] = '1';
		$robots             = EmbedMode::noindex( array( 'max-image-preview' => 'large' ) );
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );

		ob_start();
		EmbedMode::print_css();
		$this->assertStringContainsString( 'id="dscc-embed-css"', ob_get_clean() );
	}

	public function test_normal_page_is_not_noindex_and_gets_no_embed_css() {
		$robots = EmbedMode::noindex( array() );
		$this->assertArrayNotHasKey( 'noindex', $robots );

		ob_start();
		EmbedMode::print_css();
		$this->assertSame( '', ob_get_clean() );
	}
}
