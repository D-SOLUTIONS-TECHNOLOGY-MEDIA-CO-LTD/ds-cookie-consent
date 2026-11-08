<?php
/**
 * Reads the visitor's consent record from the request cookie on the server. Only used where a decision cannot wait
 * for the browser: Set-Cookie headers (ServerCookieGuard) and server-side events of tracking plugins. Page output never
 * depends on it, so cached pages stay identical for every visitor.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

use DSolutions\CookieConsent\Frontend\UserAgentRules;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class ConsentReader {

	/**
	 * Valid consent record of the current request, or null when there is none, it expired or it belongs to another
	 * policy version.
	 *
	 * @return array|null Record {v,id,t,c,s,src}.
	 */
	public static function current() {
		$adv = Settings::group( 'advanced' );
		$key = (string) $adv['cookie_name'];
		if ( ! isset( $_COOKIE[ $key ] ) || ! is_string( $_COOKIE[ $key ] ) ) {
			return null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded as JSON and validated field by field in parse().
		return self::parse( wp_unslash( $_COOKIE[ $key ] ), (string) $adv['policy_version'], (int) $adv['consent_days'], time() );
	}

	/**
	 * The consent that applies to this request: the visitor's cookie, or, for a user agent on the admin's skip list when
	 * `advanced.skip_ua_mode` is `grant` (a host app owns consent), everything allowed.
	 *
	 * @return array|null
	 */
	public static function effective() {
		$record = self::current();
		if ( null !== $record ) {
			return $record;
		}
		$adv = Settings::group( 'advanced' );
		if ( 'grant' !== $adv['skip_ua_mode'] || empty( $adv['skip_ua_patterns'] ) ) {
			return null;
		}
		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against patterns.
		if ( ! UserAgentRules::matches( (array) $adv['skip_ua_patterns'], $agent ) ) {
			return null;
		}
		return array(
			'v'   => (string) $adv['policy_version'],
			'id'  => 'host-app',
			't'   => time(),
			'c'   => array(
				'analytics'  => 1,
				'marketing'  => 1,
				'functional' => 1,
			),
			's'   => array(),
			'src' => 'host-app',
		);
	}

	/**
	 * Parse and validate a raw cookie value (PHP has already URL-decoded it once; a second decode is tried when the
	 * first one is not JSON).
	 *
	 * @param string $raw     Cookie value.
	 * @param string $version Current policy version.
	 * @param int    $days    Days a choice stays valid.
	 * @param int    $now     Unix time.
	 * @return array|null
	 */
	public static function parse( $raw, $version, $days, $now ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 4096 ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( rawurldecode( $raw ), true );
		}
		$rec = self::normalise( $data, (string) $version );
		if ( null === $rec || $rec['t'] <= 0 || $rec['t'] + ( (int) $days * 86400 ) <= (int) $now ) {
			return null;
		}
		return $rec;
	}

	/**
	 * May something of this category (and service) run for this record? A service without its own entry follows its
	 * category. A category value with spaces ("analytics marketing") is satisfied by any of its parts.
	 *
	 * @param array|null $rec      Record from current()/parse(), or null for "no choice yet".
	 * @param string     $category Category key(s).
	 * @param string     $slug     Service slug ('' = category only).
	 * @return bool
	 */
	public static function allows( $rec, $category, $slug = '' ) {
		$parts = preg_split( '/\s+/', trim( (string) $category ) );
		if ( in_array( 'necessary', $parts, true ) ) {
			return true;
		}
		if ( ! is_array( $rec ) ) {
			return false;
		}
		foreach ( $parts as $part ) {
			if ( '' !== $part && ! empty( $rec['c'][ $part ] ) && ( '' === $slug || ! isset( $rec['s'][ $slug ] ) || ! empty( $rec['s'][ $slug ] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalise a decoded consent cookie {v,id,t,c,s,src}. Returns null when it is malformed or belongs to another
	 * policy version.
	 *
	 * @param mixed  $raw     Decoded JSON value.
	 * @param string $version Current policy version.
	 * @return array|null
	 */
	private static function normalise( $raw, $version ) {
		if ( ! is_array( $raw ) || ! isset( $raw['v'], $raw['id'], $raw['c'] ) || (string) $raw['v'] !== $version || ! is_array( $raw['c'] ) || ! is_string( $raw['id'] ) ) {
			return null;
		}
		$id = substr( (string) preg_replace( '/[^a-zA-Z0-9\-]/', '', $raw['id'] ), 0, 64 );
		if ( '' === $id ) {
			return null;
		}
		$out = array(
			'v'   => $version,
			'id'  => $id,
			't'   => isset( $raw['t'] ) ? (int) $raw['t'] : 0,
			'c'   => array(),
			's'   => array(),
			'src' => isset( $raw['src'] ) && is_string( $raw['src'] ) ? $raw['src'] : 'banner',
		);
		foreach ( $raw['c'] as $key => $flag ) {
			$out['c'][ (string) $key ] = empty( $flag ) ? 0 : 1;
		}
		if ( isset( $raw['s'] ) && is_array( $raw['s'] ) ) {
			foreach ( $raw['s'] as $slug => $flag ) {
				$out['s'][ (string) $slug ] = empty( $flag ) ? 0 : 1;
			}
		}
		return $out;
	}
}
