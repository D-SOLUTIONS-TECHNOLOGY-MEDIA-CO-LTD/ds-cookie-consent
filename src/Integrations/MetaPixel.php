<?php
/**
 * Meta Pixel for WordPress (official-facebook-pixel).
 *
 * The plugin has its own hold mechanism: the filter `facebook_signals_held` (true = no `_fbp` cookie from the server,
 * no fbq('init'), events queued) and, in the browser, window.fbwcsignal.hold()/release(). Without a consent plugin the
 * default is "active", so this class holds the plugin in every rendered page; the browser script releases it when the
 * visitor allows the Meta Pixel. Holding does not depend on the visitor, so the page HTML stays identical for everyone.
 *
 * The filter must exist before the plugin runs its constructor (plugin load time), hence register_early().
 * ServerCookieGuard and the script blocker stay in place as fallback for plugin versions without the hold filter.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Integrations;

use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class MetaPixel {

	const PLUGIN = 'official-facebook-pixel/facebook-for-wordpress.php';

	/** Cookie the plugin reads in the browser to know its hold state. */
	const STATE_COOKIE = 'wc_facebook_signals_state';

	/**
	 * Is the plugin installed and active?
	 *
	 * @return bool
	 */
	public static function detected() {
		return class_exists( 'FacebookPixelPlugin\\Core\\FacebookSignalState', false ) || Registry::plugin_active( self::PLUGIN );
	}

	/**
	 * Register the hold filter at plugin load time, before the Meta plugin initialises. Detection reads the list of
	 * active plugins only. The callback reads the settings when it runs, not now, so filters that change the defaults
	 * (registered by plugins loaded later) still apply.
	 */
	public static function register_early() {
		if ( Registry::plugin_active( self::PLUGIN ) ) {
			add_filter( 'facebook_signals_held', array( __CLASS__, 'hold' ) );
		}
	}

	/**
	 * Hooks that need the full environment (plugins_loaded).
	 */
	public static function register() {
		if ( ! has_filter( 'facebook_signals_held', array( __CLASS__, 'hold' ) ) ) {
			add_filter( 'facebook_signals_held', array( __CLASS__, 'hold' ) );
		}
		add_action( 'wp_head', array( __CLASS__, 'print_state_script' ), 1 );
		add_filter( 'dscc_catalog_services', array( __CLASS__, 'declare_state_cookie' ), 20 );
	}

	/**
	 * Is the plugin held? Only in block mode and unless the admin switched the hold off (it costs one admin-ajax call
	 * per page for visitors who allowed marketing, see the settings help).
	 *
	 * @return bool
	 */
	public static function holding() {
		return Registry::blocking_mode() && (bool) Settings::get( 'blocking', 'meta_hold', true );
	}

	/**
	 * `facebook_signals_held`: true holds the plugin for every rendered page, whatever the visitor.
	 *
	 * @param bool $held Value so far.
	 * @return bool
	 */
	public static function hold( $held ) {
		return self::holding() ? true : $held;
	}

	/**
	 * The state cookie the plugin keeps is necessary: it only remembers whether the pixel may start. Shown in the popup
	 * and the cookie tables while the hold is on.
	 *
	 * @param array $services Services so far.
	 * @return array
	 */
	public static function declare_state_cookie( $services ) {
		if ( ! self::holding() ) {
			return $services;
		}
		$services   = is_array( $services ) ? $services : array();
		$services[] = array(
			'slug'        => 'meta-pixel-plugin',
			'name'        => 'Meta Pixel for WordPress',
			'category'    => 'necessary',
			'privacy_url' => 'https://www.facebook.com/privacy/policy/',
			'blockable'   => false,
			'cookies'     => array(
				array(
					'name'     => self::STATE_COOKIE,
					'duration' => array(
						'vi' => '1 năm',
						'en' => '1 year',
					),
					'purpose'  => array(
						'vi' => 'Ghi nhớ việc khách có đồng ý cookie tiếp thị hay chưa, để plugin Meta Pixel chỉ khởi tạo pixel khi được phép',
						'en' => 'Remembers whether marketing cookies were allowed, so the Meta Pixel plugin only starts the pixel when permitted',
					),
				),
			),
		);
		return $services;
	}

	/**
	 * Tiny inline script: without marketing consent the plugin's state cookie says "held", so its browser code does
	 * not start the pixel on pages served from a cache. Same text for every visitor; the decision is made in the browser.
	 */
	public static function print_state_script() {
		if ( ! self::holding() ) {
			return;
		}
		$js = "(function(){try{var b=window.DSCC_BOOT,c=b&&b.consent;if(!(c&&c.m))document.cookie='" . self::STATE_COOKIE . "=held; path=/; max-age=31536000; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'')}catch(e){}})();";
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant script text.
		echo '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" data-dscc-ignore id="dscc-meta-state">' . $js . "</script>\n";
	}

	/**
	 * Name for the admin list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'Meta Pixel for WordPress';
	}

	/**
	 * Admin note.
	 *
	 * @return string
	 */
	public static function note() {
		return __( 'The plugin is kept on "hold" until the visitor accepts Marketing (no _fbp is set by the server, no pixel is initialised); the browser calls release() once the visitor accepts.', 'ds-cookie-consent' );
	}
}
