<?php
/**
 * Validates one beacon payload from a visitor's browser: names and hosts only, never values. Pure: no WordPress calls.
 *
 * Payload (JSON): {c: [cookie names], l: [localStorage keys], x: [sessionStorage keys], h: [resource hosts],
 * s: {c: {analytics: 0|1, marketing: 0|1, ...}, s: {slug: 0|1}}, u: "/path"}.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

final class BeaconValidator {

	const MAX_COOKIES  = 60;
	const MAX_KEYS     = 40;
	const MAX_HOSTS    = 60;
	const MAX_SERVICES = 60;
	const MAX_NAME     = 100;

	/** Cookies of the WordPress admin area and logged-in users: never recorded. */
	const IGNORED_COOKIES = array( 'wordpress_*', 'wp-settings-*', 'wp-settings-time-*', 'wp_lang', 'wordpress_test_cookie' );

	/**
	 * Validate and normalise.
	 *
	 * @param mixed $raw JSON string or decoded array.
	 * @return array {ok, error, data: {cookies, local, session, hosts, consent, path}}
	 */
	public static function validate( $raw ) {
		$fail = static function ( $code ) {
			return array(
				'ok'    => false,
				'error' => $code,
				'data'  => array(),
			);
		};
		if ( is_string( $raw ) ) {
			if ( strlen( $raw ) > 4096 ) {
				return $fail( 'too_large' );
			}
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return $fail( 'bad_payload' );
		}
		$lists = array(
			'c' => array( self::MAX_COOKIES, 'cookies' ),
			'l' => array( self::MAX_KEYS, 'local' ),
			'x' => array( self::MAX_KEYS, 'session' ),
			'h' => array( self::MAX_HOSTS, 'hosts' ),
		);
		$data  = array();
		foreach ( $lists as $key => $def ) {
			$value = isset( $raw[ $key ] ) ? $raw[ $key ] : array();
			if ( ! is_array( $value ) || count( $value ) > $def[0] ) {
				return $fail( 'bad_' . $def[1] );
			}
			$data[ $def[1] ] = array();
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) ) {
					return $fail( 'bad_' . $def[1] );
				}
				$clean = 'h' === $key ? self::host( $item ) : self::name( $item, 'c' === $key );
				if ( '' !== $clean ) {
					$data[ $def[1] ][] = $clean;
				}
			}
			$data[ $def[1] ] = array_values( array_unique( $data[ $def[1] ] ) );
		}
		$consent = self::consent( isset( $raw['s'] ) ? $raw['s'] : null );
		if ( null === $consent ) {
			return $fail( 'bad_consent' );
		}
		$data['consent'] = $consent;
		$data['path']    = isset( $raw['u'] ) && is_string( $raw['u'] ) ? \DSolutions\CookieConsent\Consent\LogValidator::path_only( $raw['u'] ) : '';
		return array(
			'ok'    => true,
			'error' => '',
			'data'  => $data,
		);
	}

	/**
	 * Is the name one the WordPress admin area uses?
	 *
	 * @param string $name Cookie name.
	 * @return bool
	 */
	public static function is_admin_cookie( $name ) {
		foreach ( self::IGNORED_COOKIES as $pattern ) {
			if ( \DSolutions\CookieConsent\Catalog\PatternMatcher::name_matches( $pattern, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A cookie or storage key. Observed names are always literal: wildcards, "=", whitespace, ";" and "," are refused
	 * (a "*" would become a catalogue pattern matching every cookie), and so are names that look like personal data
	 * (an email address, a UUID, 8 or more digits in a row, 16 or more hex characters in a row).
	 *
	 * @param string $name      Raw name.
	 * @param bool   $is_cookie Whether it is a cookie (admin cookies are dropped too).
	 * @return string Clean name or ''.
	 */
	private static function name( $name, $is_cookie ) {
		if ( ! self::literal_name( $name ) || self::looks_personal( $name ) ) {
			return '';
		}
		return $is_cookie && self::is_admin_cookie( $name ) ? '' : $name;
	}

	/**
	 * Printable ASCII of at most 100 characters without wildcard, assignment, separator, whitespace or markup characters.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function literal_name( $name ) {
		return is_string( $name ) && (bool) preg_match( '/^[\x21-\x7E]{1,' . self::MAX_NAME . '}$/', $name ) && ! preg_match( '/[*?=;,<>"\'`\\\\]/', $name );
	}

	/**
	 * Does a name embed an identifier of a person or session?
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function looks_personal( $name ) {
		return false !== strpos( $name, '@' )
			|| (bool) preg_match( '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', $name )
			|| (bool) preg_match( '/\d{8,}/', $name )
			|| (bool) preg_match( '/[0-9a-f]{16,}/i', $name );
	}

	/**
	 * A host name with at least one dot.
	 *
	 * @param string $host Raw host.
	 * @return string Lower-case host or ''.
	 */
	private static function host( $host ) {
		$host = strtolower( trim( $host ) );
		return strlen( $host ) <= 100 && preg_match( '/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $host ) && false !== strpos( $host, '.' ) ? $host : '';
	}

	/**
	 * Reported consent: both optional categories must be present.
	 *
	 * @param mixed $value Raw value.
	 * @return array|null {c: {name: 0|1}, s: {slug: 0|1}} or null.
	 */
	private static function consent( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['c'] ) || ! is_array( $value['c'] ) ) {
			return null;
		}
		$out = array(
			'c' => array(),
			's' => array(),
		);
		foreach ( $value['c'] as $key => $flag ) {
			if ( ! is_string( $key ) || ! preg_match( '/^[a-z_]{1,20}$/', $key ) || ! self::flag( $flag ) || count( $out['c'] ) >= 8 ) {
				return null;
			}
			$out['c'][ $key ] = $flag ? 1 : 0;
		}
		if ( ! array_key_exists( 'analytics', $out['c'] ) || ! array_key_exists( 'marketing', $out['c'] ) ) {
			return null;
		}
		$services = isset( $value['s'] ) ? $value['s'] : array();
		if ( ! is_array( $services ) || count( $services ) > self::MAX_SERVICES ) {
			return null;
		}
		foreach ( $services as $slug => $flag ) {
			if ( ! is_string( $slug ) || ! preg_match( '/^[a-z0-9_\-]{1,40}$/', $slug ) || ! self::flag( $flag ) ) {
				return null;
			}
			$out['s'][ $slug ] = $flag ? 1 : 0;
		}
		return $out;
	}

	/**
	 * 0/1/bool flag.
	 *
	 * @param mixed $flag Raw flag.
	 * @return bool
	 */
	private static function flag( $flag ) {
		return is_int( $flag ) || is_bool( $flag ) || '0' === $flag || '1' === $flag;
	}
}
