<?php
/**
 * PixelYourSite: its GDPR filters.
 *
 * Browser-side pixels ("disabled by API") are answered from the visitor's consent only when the filter runs in an
 * AJAX request, which PixelYourSite uses to refresh the values on cached pages (`pys_gdpr_ajax_enabled`). In a normal
 * page render the answer is always "disabled", so the HTML is the same for every visitor. Server-side events
 * (`pys_check_consent_by_gdpr`) read the real consent of the request.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Integrations;

use DSolutions\CookieConsent\Consent\ConsentReader;

defined( 'ABSPATH' ) || exit;

final class PixelYourSite {

	/** Pixel suffix of `pys_disable_{pixel}_by_gdpr` => our category. */
	const PIXELS = array(
		'analytics'  => 'analytics',
		'facebook'   => 'marketing',
		'google_ads' => 'marketing',
		'pinterest'  => 'marketing',
		'bing'       => 'marketing',
		'reddit'     => 'marketing',
		'openai'     => 'marketing',
		'tiktok'     => 'marketing',
	);

	/** Pixel slug used by `pys_check_consent_by_gdpr` => our category. */
	const SERVER_PIXELS = array(
		'ga'        => 'analytics',
		'gtm'       => 'analytics',
		'facebook'  => 'marketing',
		'bing'      => 'marketing',
		'pinterest' => 'marketing',
		'reddit'    => 'marketing',
		'openai'    => 'marketing',
	);

	/**
	 * Is PixelYourSite (free or pro) loaded?
	 *
	 * @return bool
	 */
	public static function detected() {
		return defined( 'PYS_FREE_VERSION' ) || defined( 'PYS_PRO_VERSION' ) || class_exists( 'PixelYourSite\\PYS', false );
	}

	/**
	 * Hook the filters (block mode only).
	 */
	public static function register() {
		if ( ! Registry::blocking_mode() ) {
			return;
		}
		foreach ( array_keys( self::PIXELS ) as $pixel ) {
			add_filter( 'pys_disable_' . $pixel . '_by_gdpr', array( __CLASS__, 'disable_pixel' ), 10, 1 );
		}
		add_filter( 'pys_check_consent_by_gdpr', array( __CLASS__, 'check_server_event' ), 10, 2 );
		add_filter( 'pys_gdpr_ajax_enabled', '__return_true' );
	}

	/**
	 * `pys_disable_{pixel}_by_gdpr`: true disables the pixel in the browser.
	 *
	 * @param bool $disabled Value so far.
	 * @return bool
	 */
	public static function disable_pixel( $disabled ) {
		$pixel = preg_replace( '/^pys_disable_(.+)_by_gdpr$/', '$1', current_filter() );
		if ( ! isset( self::PIXELS[ $pixel ] ) ) {
			return $disabled;
		}
		if ( ! wp_doing_ajax() ) {
			return true; // Page render: never depends on the visitor.
		}
		return ! ConsentReader::allows( ConsentReader::current(), self::PIXELS[ $pixel ] ) || (bool) $disabled;
	}

	/**
	 * `pys_check_consent_by_gdpr`: may a server-side event of this pixel be sent?
	 *
	 * @param bool   $consented Value so far.
	 * @param string $pixel     Pixel slug.
	 * @return bool
	 */
	public static function check_server_event( $consented, $pixel = '' ) {
		if ( ! isset( self::SERVER_PIXELS[ $pixel ] ) ) {
			return $consented;
		}
		return (bool) $consented && ConsentReader::allows( ConsentReader::current(), self::SERVER_PIXELS[ $pixel ] );
	}

	/**
	 * Name for the admin list.
	 *
	 * @return string
	 */
	public static function label() {
		return 'PixelYourSite';
	}

	/**
	 * Admin note.
	 *
	 * @return string
	 */
	public static function note() {
		return __( 'Uses the plugin\'s GDPR filters (pys_disable_*_by_gdpr, pys_check_consent_by_gdpr) and enables value updates over AJAX so cached pages still follow the visitor\'s choice. Cost: the plugin calls admin-ajax to refresh values on every page view. How it refreshes after the visitor changes the choice has not been verified on the real plugin: test it on a staging site.', 'ds-cookie-consent' );
	}
}
