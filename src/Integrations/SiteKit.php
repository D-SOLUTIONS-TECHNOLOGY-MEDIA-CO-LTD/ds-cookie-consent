<?php
/**
 * Google Site Kit. When Site Kit's own Consent Mode is on, its tag is not blocked (Google's tag respects the Consent
 * Mode defaults and updates itself). The consent signal reaches Site Kit through the WP Consent API bridge.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Integrations;

defined( 'ABSPATH' ) || exit;

final class SiteKit {

	/** Element id prefix of the tags Site Kit prints (gtag loader, config, consent defaults). */
	const ID_PREFIX = 'google_gtagjs';

	/**
	 * Is Site Kit active?
	 *
	 * @return bool
	 */
	public static function detected() {
		return defined( 'GOOGLESITEKIT_VERSION' );
	}

	/**
	 * Keep Site Kit's tag out of the blocker while its Consent Mode is enabled.
	 */
	public static function register() {
		add_filter( 'dscc_blocker_skip_tag', array( __CLASS__, 'skip_tag' ), 10, 4 );
	}

	/**
	 * Is Site Kit's Consent Mode switched on?
	 *
	 * @return bool
	 */
	public static function consent_mode_enabled() {
		$option = get_option( 'googlesitekit_consent_mode' );
		return is_array( $option ) && ! empty( $option['enabled'] );
	}

	/**
	 * `dscc_blocker_skip_tag` callback.
	 *
	 * @param bool   $skip  Value so far.
	 * @param string $kind  script|iframe|img.
	 * @param array  $attrs Attributes keyed by lower-case name, each {name, value, start, end}.
	 * @return bool
	 */
	public static function skip_tag( $skip, $kind, $attrs ) {
		if ( $skip || 'script' !== $kind || ! is_array( $attrs ) || ! self::consent_mode_enabled() ) {
			return $skip;
		}
		return isset( $attrs['id']['value'] ) && 0 === strpos( (string) $attrs['id']['value'], self::ID_PREFIX );
	}

	/**
	 * Name for the admin list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'Google Site Kit';
	}

	/**
	 * Admin note.
	 *
	 * @return string
	 */
	public static function note() {
		if ( self::detected() && ! self::consent_mode_enabled() ) {
			return __( 'Site Kit Consent Mode is off: the Google tags of Site Kit are blocked until the visitor accepts Analytics. You can turn on Consent Mode in Site Kit (Settings > Admin Settings > Consent Mode) so the tags load in denied mode.', 'ds-cookie-consent' );
		}
		return __( 'Site Kit Consent Mode is on: Site Kit tags are not blocked; the visitor\'s choice is passed on through the WP Consent API.', 'ds-cookie-consent' );
	}
}
