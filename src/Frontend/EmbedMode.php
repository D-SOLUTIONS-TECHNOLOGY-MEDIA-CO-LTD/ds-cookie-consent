<?php
/**
 * ?dscc_embed=1: policy pages shown inside the popup iframe, without theme chrome.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

defined( 'ABSPATH' ) || exit;

final class EmbedMode {

	const CSS = 'html{margin-top:0!important}#wpadminbar,body header:not(.entry-header):not(.page-header),body footer,#masthead,#colophon,.site-header,.site-footer,nav.main-navigation,.sidebar,#sidebar,.widget-area,.breadcrumbs{display:none!important}body{padding-top:0!important;background:#fff!important}';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'print_css' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'noindex' ) );
	}

	/**
	 * Whether this request is an embedded policy view.
	 *
	 * @return bool
	 */
	public static function is_embed_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag on a public page.
		return isset( $_GET['dscc_embed'] ) && '1' === $_GET['dscc_embed'] && ! is_admin();
	}

	/**
	 * Hide header, footer and navigation of the theme.
	 */
	public static function print_css() {
		if ( self::is_embed_request() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- constant string.
			echo '<style id="dscc-embed-css">' . self::CSS . "</style>\n";
		}
	}

	/**
	 * Keep embedded views out of search results.
	 *
	 * @param array $robots Robots directives.
	 * @return array
	 */
	public static function noindex( $robots ) {
		if ( self::is_embed_request() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}
}
