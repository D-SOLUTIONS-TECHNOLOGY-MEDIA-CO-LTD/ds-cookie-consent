<?php
use DSolutions\CookieConsent\Frontend\LanguageResolver;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Visitor language for server-rendered strings: WPML, then Polylang, then the locale; only vi and en are supported.
 */
class Dscc_Language_Resolver_Test extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'wpml_current_language' );
		remove_all_filters( 'dscc_default_language' );
		remove_all_filters( 'dscc_client_side_language' );
		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'default_lang' => 'vi', 'lang_source' => 'server' ) ) );
		parent::tear_down();
	}

	public function test_vi_and_en_map_to_themselves_and_other_locales_to_the_default() {
		$this->assertSame( 'vi', LanguageResolver::map( 'vi_VN' ) );
		$this->assertSame( 'vi', LanguageResolver::map( 'VI' ) );
		$this->assertSame( 'en', LanguageResolver::map( 'en_US' ) );
		$this->assertSame( 'vi', LanguageResolver::map( 'fr_FR' ), 'Vietnamese is the default for other languages' );
	}

	public function test_default_language_setting_decides_other_locales() {
		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'default_lang' => 'en' ) ) );
		$this->assertSame( 'en', LanguageResolver::default_language() );
		add_filter( 'wpml_current_language', static function () {
			return 'fr';
		} );
		$this->assertSame( 'en', LanguageResolver::current() );
		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'default_lang' => 'vi' ) ) );
		$this->assertSame( 'vi', LanguageResolver::current() );
	}

	public function test_client_side_language_follows_setting_or_gtranslate() {
		$this->assertFalse( LanguageResolver::is_client_side() );
		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'lang_source' => 'client' ) ) );
		$this->assertTrue( LanguageResolver::is_client_side() );
		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'lang_source' => 'server' ) ) );
		$this->assertFalse( LanguageResolver::is_client_side() );
		add_filter( 'dscc_client_side_language', '__return_true' );
		$this->assertTrue( LanguageResolver::is_client_side() );
	}

	public function test_unknown_language_uses_forced_default_when_set() {
		$this->assertSame( 'vi', LanguageResolver::map( 'fr_FR', 'vi' ) );
		$this->assertSame( 'en', LanguageResolver::map( 'fr_FR', 'en' ) );
	}

	public function test_test_site_locale_resolves_to_english() {
		$this->assertSame( 'en', LanguageResolver::current() );
	}

	public function test_wpml_language_wins_over_locale() {
		add_filter(
			'wpml_current_language',
			static function () {
				return 'vi';
			}
		);
		$this->assertSame( 'vi', LanguageResolver::current() );
	}

	public function test_unsupported_wpml_language_falls_back_to_forced_default() {
		add_filter(
			'wpml_current_language',
			static function () {
				return 'fr';
			}
		);
		$this->assertSame( 'vi', LanguageResolver::current() );

		add_filter(
			'dscc_default_language',
			static function () {
				return 'en';
			}
		);
		$this->assertSame( 'en', LanguageResolver::current() );
	}
}
