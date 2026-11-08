<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Blocker\ScriptRules;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Integrations\LiteSpeedCache;
use DSolutions\CookieConsent\Integrations\MetaPixel;
use DSolutions\CookieConsent\Integrations\PixelYourSite;
use DSolutions\CookieConsent\Integrations\Registry;
use DSolutions\CookieConsent\Integrations\SiteKit;
use DSolutions\CookieConsent\Integrations\WpConsentApi;
use DSolutions\CookieConsent\Integrations\WpRocket;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Filters of the integrations: only hooked when the plugin is present, correct values, no dependency on the visitor in
 * page output.
 */
class Dscc_Integrations_Test extends WP_UnitTestCase {

	/** Hooks registered by tests that must be removed again. */
	private $hooks = array(
		'facebook_signals_held',
		'pys_disable_analytics_by_gdpr',
		'pys_disable_facebook_by_gdpr',
		'pys_disable_google_ads_by_gdpr',
		'pys_check_consent_by_gdpr',
		'pys_gdpr_ajax_enabled',
		'litespeed_optimize_js_excludes',
		'litespeed_optm_js_defer_exc',
		'rocket_exclude_js',
		'rocket_exclude_defer_js',
		'rocket_delay_js_exclusions',
		'rocket_defer_inline_exclusions',
		'dscc_blocker_skip_tag',
		'wp_get_consent_type',
		'dscc_integration_detected',
	);

	public function set_up() {
		parent::set_up();
		Activator::install();
		Settings::save_group( 'blocking', array_merge( Settings::group( 'blocking' ), array( 'mode' => 'block' ) ) );
		ScriptRules::flush();
	}

	public function tear_down() {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}
		remove_all_actions( 'wp_head' );
		remove_filter( 'dscc_catalog_services', array( MetaPixel::class, 'declare_state_cookie' ), 20 );
		unset( $_COOKIE['dscc_consent'] );
		ScriptRules::flush();
		parent::tear_down();
	}

	private function detect( array $slugs ) {
		add_filter(
			'dscc_integration_detected',
			static function ( $found, $slug ) use ( $slugs ) {
				return in_array( $slug, $slugs, true ) ? true : $found;
			},
			10,
			2
		);
	}

	private function consent( array $c, array $s = array() ) {
		$_COOKIE['dscc_consent'] = wp_json_encode(
			array(
				'v'   => Settings::get( 'advanced', 'policy_version' ),
				'id'  => 'LittleD-visitor',
				't'   => time(),
				'c'   => $c,
				's'   => $s,
				'src' => 'banner',
			)
		);
	}

	public function test_nothing_is_hooked_when_the_other_plugins_are_absent() {
		Registry::register();
		foreach ( array( 'facebook_signals_held', 'pys_gdpr_ajax_enabled', 'litespeed_optimize_js_excludes', 'rocket_exclude_js', 'dscc_blocker_skip_tag', 'wp_get_consent_type' ) as $hook ) {
			$this->assertFalse( has_filter( $hook ), $hook . ' must stay unhooked' );
		}
		$this->assertSame( array( 'wpc' => 0, 'fbs' => 0 ), Registry::client_flags() );
	}

	public function test_litespeed_exclusions_add_our_script_and_the_vendor_hosts() {
		PlatformManager::enable( 'google-analytics' );
		$this->detect( array( 'litespeed-cache' ) );
		Registry::register();

		$js = apply_filters( 'litespeed_optimize_js_excludes', array( 'existing.js' ) );
		$this->assertContains( 'existing.js', $js );
		$this->assertContains( 'assets/dist/dscc.js', $js );
		$this->assertContains( 'google-analytics.com', $js );

		$defer = apply_filters( 'litespeed_optm_js_defer_exc', array() );
		$this->assertContains( '/*data-dscc*/', $defer, 'inline blocked scripts carry the marker' );
		$this->assertSame( array_values( array_unique( $js ) ), $js, 'no duplicates' );
	}

	public function test_wp_rocket_exclusions_use_the_right_shape_per_filter() {
		PlatformManager::enable( 'google-analytics' );
		$this->detect( array( 'wp-rocket' ) );
		Registry::register();

		$exclude = apply_filters( 'rocket_exclude_js', array() );
		$this->assertContains( 'assets/dist/dscc\.js', $exclude, 'regex fragments are escaped' );
		$this->assertContains( preg_quote( 'google-analytics.com', '#' ), $exclude );
		$this->assertSame( $exclude, apply_filters( 'rocket_exclude_defer_js', array() ) );

		$delay = apply_filters( 'rocket_delay_js_exclusions', array() );
		$this->assertContains( 'data-dscc', $delay, 'delay JS matches the whole tag, the attribute marker works' );

		$inline = apply_filters( 'rocket_defer_inline_exclusions', array() );
		$this->assertSame( array( '/*data-dscc*/' ), $inline, 'literal strings matched against the script body' );
		$this->assertSame( array( 'abc', '/*data-dscc*/' ), apply_filters( 'rocket_defer_inline_exclusions', 'abc' ), 'the string form is accepted' );
	}

	public function test_meta_pixel_is_held_in_every_rendered_page_whatever_the_visitor() {
		$this->detect( array( 'meta-pixel' ) );
		Registry::register();
		$this->assertTrue( apply_filters( 'facebook_signals_held', false ) );
		$this->consent( array( 'analytics' => 1, 'marketing' => 1 ) );
		$this->assertTrue( apply_filters( 'facebook_signals_held', false ), 'page output must not depend on the visitor (page cache)' );
		$this->assertSame( array( 'wpc' => 0, 'fbs' => 1 ), Registry::client_flags() );

		ob_start();
		do_action( 'wp_head' );
		$head = ob_get_clean();
		$this->assertStringContainsString( 'id="dscc-meta-state"', $head );
		$this->assertStringContainsString( 'wc_facebook_signals_state=held', $head );
	}

	public function test_meta_pixel_hold_is_not_applied_in_observe_mode() {
		Settings::save_group( 'blocking', array_merge( Settings::group( 'blocking' ), array( 'mode' => 'observe' ) ) );
		$this->detect( array( 'meta-pixel' ) );
		Registry::register();
		$this->assertFalse( apply_filters( 'facebook_signals_held', false ), 'observe mode never holds the plugin' );
		$this->assertSame( 0, Registry::client_flags()['fbs'] );
	}

	public function test_meta_hold_can_be_switched_off_and_its_state_cookie_is_declared_as_necessary() {
		$this->detect( array( 'meta-pixel' ) );
		Registry::register();
		$services = apply_filters( 'dscc_catalog_services', array() );
		$slugs    = wp_list_pluck( $services, 'slug' );
		$this->assertContains( 'meta-pixel-plugin', $slugs );
		$this->assertSame( 'necessary', $services[ array_search( 'meta-pixel-plugin', $slugs, true ) ]['category'] );

		Settings::save_group( 'blocking', array_merge( Settings::group( 'blocking' ), array( 'meta_hold' => false ) ) );
		$this->assertFalse( apply_filters( 'facebook_signals_held', false ) );
		$this->assertSame( 0, Registry::client_flags()['fbs'] );
		$this->assertNotContains( 'meta-pixel-plugin', wp_list_pluck( apply_filters( 'dscc_catalog_services', array() ), 'slug' ) );
		ob_start();
		do_action( 'wp_head' );
		$this->assertStringNotContainsString( 'dscc-meta-state', ob_get_clean() );
	}

	public function test_wp_consent_api_bridge_is_off_in_observe_mode() {
		Settings::save_group( 'blocking', array_merge( Settings::group( 'blocking' ), array( 'mode' => 'observe' ) ) );
		$this->detect( array( 'wp-consent-api' ) );
		Registry::register();
		$this->assertFalse( has_filter( 'wp_get_consent_type' ) );
		$this->assertSame( 0, Registry::client_flags()['wpc'] );
	}

	public function test_pixelyoursite_pixels_are_disabled_in_page_output_and_follow_consent_in_ajax() {
		$this->detect( array( 'pixelyoursite' ) );
		Registry::register();
		$this->assertTrue( has_filter( 'pys_gdpr_ajax_enabled' ) );

		// Page render: always disabled, so the HTML is the same for everyone.
		$this->consent( array( 'analytics' => 1, 'marketing' => 1 ) );
		$this->assertTrue( apply_filters( 'pys_disable_facebook_by_gdpr', false ) );
		$this->assertTrue( apply_filters( 'pys_disable_analytics_by_gdpr', false ) );

		// AJAX refresh: answered from the visitor's consent.
		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( apply_filters( 'pys_disable_facebook_by_gdpr', false ) );
		$this->assertFalse( apply_filters( 'pys_disable_analytics_by_gdpr', false ) );
		$this->consent( array( 'analytics' => 1, 'marketing' => 0 ) );
		$this->assertTrue( apply_filters( 'pys_disable_facebook_by_gdpr', false ) );
		$this->assertTrue( apply_filters( 'pys_disable_google_ads_by_gdpr', false ) );
		$this->assertFalse( apply_filters( 'pys_disable_analytics_by_gdpr', false ) );
		unset( $_COOKIE['dscc_consent'] );
		$this->assertTrue( apply_filters( 'pys_disable_analytics_by_gdpr', false ) );
		remove_filter( 'wp_doing_ajax', '__return_true' );
	}

	public function test_pixelyoursite_server_events_read_the_real_consent() {
		$this->detect( array( 'pixelyoursite' ) );
		Registry::register();
		$this->assertFalse( apply_filters( 'pys_check_consent_by_gdpr', true, 'facebook' ) );
		$this->assertTrue( apply_filters( 'pys_check_consent_by_gdpr', true, 'unknown-pixel' ), 'pixels we do not know keep the plugin answer' );
		$this->consent( array( 'analytics' => 0, 'marketing' => 1 ) );
		$this->assertTrue( apply_filters( 'pys_check_consent_by_gdpr', true, 'facebook' ) );
		$this->assertFalse( apply_filters( 'pys_check_consent_by_gdpr', true, 'ga' ) );
		$this->assertFalse( apply_filters( 'pys_check_consent_by_gdpr', false, 'facebook' ), 'never grants what the plugin refused' );
	}

	public function test_site_kit_tag_is_not_blocked_only_while_its_consent_mode_is_on() {
		$this->detect( array( 'site-kit' ) );
		Registry::register();
		$attrs = array( 'id' => array( 'name' => 'id', 'value' => 'google_gtagjs-js', 'start' => 0, 'end' => 0 ) );

		update_option( 'googlesitekit_consent_mode', array( 'enabled' => false ) );
		$this->assertFalse( apply_filters( 'dscc_blocker_skip_tag', false, 'script', $attrs, 'https://www.googletagmanager.com/gtag/js?id=G-LITTLED000' ) );

		update_option( 'googlesitekit_consent_mode', array( 'enabled' => true ) );
		$this->assertTrue( apply_filters( 'dscc_blocker_skip_tag', false, 'script', $attrs, '' ) );
		$other = array( 'id' => array( 'name' => 'id', 'value' => 'other', 'start' => 0, 'end' => 0 ) );
		$this->assertFalse( apply_filters( 'dscc_blocker_skip_tag', false, 'script', $other, '' ), 'only Site Kit tags' );
		$this->assertFalse( apply_filters( 'dscc_blocker_skip_tag', false, 'iframe', $attrs, '' ) );
		$this->assertTrue( SiteKit::consent_mode_enabled() );
	}

	public function test_wp_consent_api_bridge_declares_the_plugin_and_the_opt_in_model() {
		$this->detect( array( 'wp-consent-api' ) );
		Registry::register();
		$this->assertTrue( apply_filters( 'wp_consent_api_registered_' . plugin_basename( DSCC_FILE ), false ) );
		$this->assertSame( 'optin', apply_filters( 'wp_get_consent_type', false ) );
		$this->assertSame( 1, Registry::client_flags()['wpc'] );
	}

	public function test_status_rows_list_every_integration_with_a_note() {
		$rows = Registry::status();
		$this->assertCount( 6, $rows );
		foreach ( $rows as $row ) {
			$this->assertNotSame( '', $row['label'] );
			$this->assertFalse( $row['detected'] );
		}
		$this->assertTrue( class_exists( LiteSpeedCache::class ) && class_exists( WpRocket::class ) && class_exists( PixelYourSite::class ) && class_exists( MetaPixel::class ) && class_exists( WpConsentApi::class ) );
	}
}
