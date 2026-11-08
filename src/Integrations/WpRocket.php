<?php
/**
 * WP Rocket. `rocket_exclude_js`, `rocket_delay_js_exclusions` and `rocket_exclude_defer_js` take regular expression
 * fragments; `rocket_defer_inline_exclusions` takes literal strings matched against the inline script body. Rocket wraps
 * inline scripts that contain jQuery in a DOMContentLoaded listener even when their type is text/plain, so the
 * blocker puts a marker comment in such bodies and it is excluded here.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Integrations;

defined( 'ABSPATH' ) || exit;

final class WpRocket {

	/**
	 * Is WP Rocket active?
	 *
	 * @return bool
	 */
	public static function detected() {
		return defined( 'WP_ROCKET_VERSION' );
	}

	/**
	 * Hook the exclusion filters.
	 */
	public static function register() {
		add_filter( 'rocket_exclude_js', array( __CLASS__, 'patterns' ) );
		add_filter( 'rocket_exclude_defer_js', array( __CLASS__, 'patterns' ) );
		add_filter( 'rocket_delay_js_exclusions', array( __CLASS__, 'delay_patterns' ) );
		add_filter( 'rocket_defer_inline_exclusions', array( __CLASS__, 'inline_exclusions' ) );
	}

	/**
	 * Regex fragments for our script and the vendor hosts.
	 *
	 * @param mixed $items Current items.
	 * @return array
	 */
	public static function patterns( $items ) {
		$add = array( preg_quote( OptimizerExclusions::own_script_path(), '#' ) );
		foreach ( OptimizerExclusions::hosts() as $host ) {
			$add[] = preg_quote( $host, '#' );
		}
		return array_values( array_unique( array_merge( is_array( $items ) ? $items : array(), $add ) ) );
	}

	/**
	 * Delay JS matches the whole tag, so the attribute marker of blocked and own tags works here too.
	 *
	 * @param mixed $items Current items.
	 * @return array
	 */
	public static function delay_patterns( $items ) {
		return array_values( array_unique( array_merge( self::patterns( $items ), array( 'data-dscc' ) ) ) );
	}

	/**
	 * Literal strings matched against inline script bodies.
	 *
	 * @param mixed $items Current items (array or "|" separated string).
	 * @return array
	 */
	public static function inline_exclusions( $items ) {
		if ( is_string( $items ) ) {
			$items = '' === $items ? array() : explode( '|', $items );
		}
		return array_values( array_unique( array_merge( is_array( $items ) ? $items : array(), array( OptimizerExclusions::inline_marker() ) ) ) );
	}

	/**
	 * Name for the admin list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'WP Rocket';
	}

	/**
	 * Admin note.
	 *
	 * @return string
	 */
	public static function note() {
		return __( 'Adds exclusions for the plugin\'s scripts, blocked hosts and blocked inline scripts (data-dscc marker) from Minify, Delay and Defer JS.', 'ds-cookie-consent' );
	}
}
