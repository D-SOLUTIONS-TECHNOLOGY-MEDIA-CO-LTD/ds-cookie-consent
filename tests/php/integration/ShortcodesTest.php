<?php
use DSolutions\CookieConsent\Frontend\Shortcodes;

/**
 * [dscc_cookie_settings] renders a link that reopens the preferences.
 */
class Dscc_Shortcodes_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Shortcodes::register();
	}

	public function test_the_shortcode_is_registered_and_opens_preferences() {
		$this->assertTrue( shortcode_exists( 'dscc_cookie_settings' ) );
		$this->assertFalse( shortcode_exists( 'ip' . 'os_cookie_settings' ), 'no alias of a former plugin is registered' );

		$out = do_shortcode( '[dscc_cookie_settings]' );
		$this->assertStringContainsString( 'data-dscc-open', $out );
		$this->assertStringContainsString( 'href="#cai-dat-cookie"', $out );
	}

	public function test_default_label_is_non_empty_text() {
		$out = do_shortcode( '[dscc_cookie_settings]' );
		$this->assertMatchesRegularExpression( '/>[^<]+<\/a>$/', $out );
	}

	public function test_text_attribute_is_escaped() {
		$out = do_shortcode( '[dscc_cookie_settings text="<b>LittleD</b>"]' );
		$this->assertStringContainsString( '&lt;b&gt;LittleD&lt;/b&gt;', $out );
		$this->assertStringNotContainsString( '<b>', $out );
	}

	public function test_dual_language_mode_prints_both_labels_server_language_visible() {
		$settings                         = \DSolutions\CookieConsent\Settings\Settings::all();
		$settings['advanced']['lang_source'] = 'client';
		\DSolutions\CookieConsent\Settings\Settings::replace( $settings );
		update_option( 'dscc_texts_vi', array( 'widget_label' => 'LittleD Cai dat' ), true );
		update_option( 'dscc_texts_en', array( 'widget_label' => 'LittleD Settings' ), true );

		$out = do_shortcode( '[dscc_cookie_settings]' );
		$server = \DSolutions\CookieConsent\Frontend\LanguageResolver::current();
		$spans  = array(
			'vi' => array( 'LittleD Cai dat', '<span data-dscc-lang="vi">LittleD Cai dat</span>', '<span data-dscc-lang="vi" hidden>LittleD Cai dat</span>' ),
			'en' => array( 'LittleD Settings', '<span data-dscc-lang="en">LittleD Settings</span>', '<span data-dscc-lang="en" hidden>LittleD Settings</span>' ),
		);
		foreach ( $spans as $lang => $markup ) {
			$this->assertStringContainsString( $lang === $server ? $markup[1] : $markup[2], $out, $lang );
		}
		$this->assertStringStartsWith( '<a href="#cai-dat-cookie" data-dscc-open translate="no"><span data-dscc-lang="' . $server . '">', $out );
		$this->assertStringContainsString( 'translate="no"', $out );
		$this->assertSame( $out, do_shortcode( '[dscc_cookie_settings]' ) );

		// A custom text replaces both languages.
		$custom = do_shortcode( '[dscc_cookie_settings text="LittleD Custom"]' );
		$this->assertSame( 2, substr_count( $custom, 'LittleD Custom' ) );

		delete_option( 'dscc_texts_vi' );
		delete_option( 'dscc_texts_en' );
		$settings['advanced']['lang_source'] = 'server';
		\DSolutions\CookieConsent\Settings\Settings::replace( $settings );
		$this->assertStringNotContainsString( 'data-dscc-lang', do_shortcode( '[dscc_cookie_settings]' ) );
	}
}
