<?php
/**
 * WP Consent API bridge. Declares this plugin as a consent source (opt-in model). The browser script mirrors the
 * visitor's choice with wp_set_consent(), which also lets Site Kit and other plugins that follow the API react.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Integrations;

defined( 'ABSPATH' ) || exit;

final class WpConsentApi {

	/**
	 * Is the WP Consent API plugin active?
	 *
	 * @return bool
	 */
	public static function detected() {
		return class_exists( 'WP_CONSENT_API', false );
	}

	/**
	 * Hook the declarations.
	 */
	public static function register() {
		if ( ! Registry::blocking_mode() ) {
			return; // The observe-only mode changes nothing on the site.
		}
		add_filter( 'wp_consent_api_registered_' . plugin_basename( DSCC_FILE ), '__return_true' );
		add_filter( 'wp_get_consent_type', array( __CLASS__, 'consent_type' ) );
	}

	/**
	 * Visitors must opt in.
	 *
	 * @return string
	 */
	public static function consent_type() {
		return 'optin';
	}

	/**
	 * Name for the admin list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'WP Consent API';
	}

	/**
	 * Admin note.
	 *
	 * @return string
	 */
	public static function note() {
		return __( 'The visitor\'s choice is synced to the statistics, marketing and preferences categories of the WP Consent API.', 'ds-cookie-consent' );
	}
}
