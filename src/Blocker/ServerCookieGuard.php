<?php
/**
 * Drops Set-Cookie headers of cookies the visitor has not agreed to (analytics, marketing, unclassified-known).
 * Runs inside the output buffer callback: the body has not been sent yet, so the headers can still change.
 * (A shutdown hook is too late: headers are gone by then.)
 *
 * Limit: it only acts on requests that reach PHP. A page served from a page cache never runs this, so the real fix
 * for a plugin that writes cookies on the server is to stop it at the source (see the integrations).
 *
 * Cookies that are not in the catalog, or belong to necessary services, are never touched, and neither are headers
 * that delete a cookie.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

use DSolutions\CookieConsent\Catalog\PatternMatcher;
use DSolutions\CookieConsent\Consent\ConsentReader;

defined( 'ABSPATH' ) || exit;

final class ServerCookieGuard {

	/**
	 * Names removed by the last apply() of this request (the scanner reads them to learn what the site would set).
	 *
	 * @var string[]
	 */
	private static $removed = array();

	/**
	 * Names of the cookies the guard removed during this request.
	 *
	 * @return string[]
	 */
	public static function removed_names() {
		return self::$removed;
	}

	/**
	 * Remove forbidden Set-Cookie headers from the response being built.
	 *
	 * @param array[]    $cookies Catalog cookie entries [name, storage, slug, category].
	 * @param array|null $consent Visitor consent record or null.
	 * @return string[] Names of the cookies that were removed.
	 */
	public static function apply( array $cookies, $consent ) {
		if ( ! $cookies || headers_sent() ) {
			return array();
		}
		$lines = array();
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Set-Cookie:' ) ) {
				$lines[] = $header;
			}
		}
		if ( ! $lines ) {
			return array();
		}
		$split = self::split( $lines, $cookies, $consent, CookieSafelist::patterns() );
		if ( ! $split['removed'] ) {
			return array();
		}
		self::$removed = $split['names'];
		header_remove( 'Set-Cookie' ); // Removes every Set-Cookie header; the allowed ones are added back below.
		foreach ( $split['keep'] as $line ) {
			header( $line, false );
		}
		return $split['names'];
	}

	/**
	 * Decide which header lines stay.
	 *
	 * @param string[]      $lines   "Set-Cookie: name=value; ..." lines.
	 * @param array[]       $cookies Catalog cookie entries [name, storage, slug, category].
	 * @param array|null    $consent Consent record or null.
	 * @param string[]|null $keep Safelisted name patterns (default: the built-in list without the consent cookie).
	 * @return array{keep:string[],removed:string[],names:string[]}
	 */
	public static function split( array $lines, array $cookies, $consent, $keep = null ) {
		$keep = is_array( $keep ) ? $keep : CookieSafelist::PATTERNS;
		$out  = array(
			'keep'    => array(),
			'removed' => array(),
			'names'   => array(),
		);
		foreach ( $lines as $line ) {
			$name = self::cookie_name( $line );
			$hit  = '' === $name || self::is_deletion( $line ) || CookieSafelist::matches( $name, $keep ) ? null : self::lookup( $name, $cookies );
			if ( $hit && ! ConsentReader::allows( $consent, $hit[3], $hit[2] ) ) {
				$out['removed'][] = $line;
				$out['names'][]   = $name;
			} else {
				$out['keep'][] = $line;
			}
		}
		return $out;
	}

	/**
	 * Name of the cookie a header line sets.
	 *
	 * @param string $line Header line.
	 * @return string
	 */
	public static function cookie_name( $line ) {
		$value = ltrim( (string) substr( $line, strlen( 'Set-Cookie:' ) ) );
		$eq    = strpos( $value, '=' );
		if ( false === $eq ) {
			return '';
		}
		return rawurldecode( trim( substr( $value, 0, $eq ) ) );
	}

	/**
	 * A header that expires a cookie: Max-Age of 0 or less, or an Expires date in the 1960s or 1970. A bare
	 * `name=deleted` value without an expiry is a normal cookie.
	 *
	 * @param string $line Header line.
	 * @return bool
	 */
	private static function is_deletion( $line ) {
		return (bool) preg_match( '/;\s*max-age\s*=\s*(0|-\d+)\s*(;|$)|;\s*expires\s*=[^;]*\b19[0-6]\d\b|;\s*expires\s*=[^;]*\b1970\b/i', $line );
	}

	/**
	 * Catalog entry for a cookie name (cookies only, not web storage).
	 *
	 * @param string  $name    Cookie name.
	 * @param array[] $cookies Entries.
	 * @return array|null
	 */
	private static function lookup( $name, array $cookies ) {
		foreach ( $cookies as $entry ) {
			if ( 'cookie' === $entry[1] && PatternMatcher::name_matches( $entry[0], $name ) ) {
				return $entry;
			}
		}
		return null;
	}
}
