<?php
/**
 * Marks the scanner's own requests. The server scan appends a short-lived signed token to the URL; a request that
 * carries a valid token is answered like any visitor without consent (the blocker and the cookie guard still run) but
 *   - is never stored by a page cache (no-cache headers, DONOTCACHEPAGE), and
 *   - lists the cookies the guard removed in the response header X-DSCC-Held, so the scan learns what the site WOULD set
 *     without that being sent to the scanner as a cookie.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Blocker\ServerCookieGuard;

defined( 'ABSPATH' ) || exit;

final class ScanRequest {

	const PARAM  = 'dscc_scan';
	const HEADER = 'X-DSCC-Held';

	/** Token validity window (a token is valid in its own window and the next one). */
	const WINDOW = 900;

	/**
	 * Register hooks. Priority 0: the buffer opened here wraps the blocker's buffer, so its callback sees the final
	 * state of the guard.
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_start' ), 0 );
	}

	/**
	 * Token for the current time window.
	 *
	 * @param int $back How many windows back (0 = current).
	 * @return string
	 */
	public static function token( $back = 0 ) {
		$window = (int) floor( time() / self::WINDOW ) - (int) $back;
		return substr( wp_hash( 'dscc_scan|' . $window ), 0, 24 );
	}

	/**
	 * Is this token valid now?
	 *
	 * @param mixed $token Value from the request.
	 * @return bool
	 */
	public static function valid( $token ) {
		return is_string( $token ) && 24 === strlen( $token ) && ( hash_equals( self::token( 0 ), $token ) || hash_equals( self::token( 1 ), $token ) );
	}

	/**
	 * Does the current request carry a valid token?
	 *
	 * @return bool
	 */
	public static function is_scan_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a signed, expiring token replaces a nonce for this machine-to-machine request; it is only compared with hash_equals().
		return isset( $_GET[ self::PARAM ] ) && self::valid( is_string( $_GET[ self::PARAM ] ) ? wp_unslash( $_GET[ self::PARAM ] ) : null );
		// phpcs:enable
	}

	/**
	 * Prepare the response of a scan request.
	 */
	public static function maybe_start() {
		if ( ! self::is_scan_request() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant page-cache plugins look for.
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
			header( 'X-Robots-Tag: noindex' );
		}
		ob_start( array( __CLASS__, 'finish' ) );
	}

	/**
	 * Output buffer callback: add the held-cookie header, leave the page untouched.
	 *
	 * @return false
	 */
	public static function finish() {
		$names = array();
		foreach ( ServerCookieGuard::removed_names() as $name ) {
			$clean = (string) preg_replace( '/[^A-Za-z0-9_.\-%]/', '', (string) $name );
			if ( '' !== $clean ) {
				$names[] = $clean;
			}
		}
		if ( $names && ! headers_sent() ) {
			header( self::HEADER . ': ' . implode( ',', array_slice( array_unique( $names ), 0, 60 ) ) );
		}
		return false;
	}
}
