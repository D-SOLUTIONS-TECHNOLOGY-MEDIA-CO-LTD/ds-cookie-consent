<?php
/**
 * LiteSpeed Cache. Its exclusion filters take plain substrings (a leading ^ or trailing $ anchors them).
 * Blocked tags are type="text/plain", which LiteSpeed skips; the entries cover our script and the vendor hosts of the
 * active rules so a combined file never contains them.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Integrations;

defined( 'ABSPATH' ) || exit;

final class LiteSpeedCache {

	/**
	 * Is LiteSpeed Cache active?
	 *
	 * @return bool
	 */
	public static function detected() {
		return defined( 'LSCWP_V' );
	}

	/**
	 * Hook the exclusion filters.
	 */
	public static function register() {
		add_filter( 'litespeed_optimize_js_excludes', array( __CLASS__, 'excludes' ) );
		add_filter( 'litespeed_optm_js_defer_exc', array( __CLASS__, 'defer_excludes' ) );
	}

	/**
	 * Substrings kept out of combine/minify.
	 *
	 * @param mixed $items Current items.
	 * @return array
	 */
	public static function excludes( $items ) {
		return array_values( array_unique( array_merge( is_array( $items ) ? $items : array(), array( OptimizerExclusions::own_script_path() ), OptimizerExclusions::hosts() ) ) );
	}

	/**
	 * Substrings kept out of defer/delay (matched against the src or the inline content).
	 *
	 * @param mixed $items Current items.
	 * @return array
	 */
	public static function defer_excludes( $items ) {
		return array_values( array_unique( array_merge( is_array( $items ) ? $items : array(), array( OptimizerExclusions::own_script_path(), OptimizerExclusions::inline_marker() ), OptimizerExclusions::hosts() ) ) );
	}

	/**
	 * Name for the admin list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'LiteSpeed Cache';
	}

	/**
	 * Admin note.
	 *
	 * @return string
	 */
	public static function note() {
		return __( 'The plugin\'s scripts and the blocked hosts are added to the automatic Combine/Defer exclusion list. The cache stores the blocked HTML, identical for every visitor.', 'ds-cookie-consent' );
	}
}
