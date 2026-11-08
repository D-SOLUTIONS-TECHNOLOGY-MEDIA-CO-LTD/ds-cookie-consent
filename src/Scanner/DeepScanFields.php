<?php
/**
 * Field validators for one item of a deep scan report (page address, host, cookie, storage key, Set-Cookie names).
 * Each returns the normalised item, or a string error code ("bad_field" or "unknown_field:<key>") when the item is
 * invalid. Cookie values are not part of the schema: a `value` key is an unknown field. Pure: no WordPress calls.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

final class DeepScanFields {

	const SAME_SITE = array( 'None', 'Lax', 'Strict' );

	/**
	 * First key of an array that is not in the whitelist.
	 *
	 * @param array    $value   Array to check.
	 * @param string[] $allowed Allowed keys.
	 * @return string|null
	 */
	public static function unknown_key( array $value, array $allowed ) {
		foreach ( array_keys( $value ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				return (string) $key;
			}
		}
		return null;
	}

	/**
	 * A page address (http or https).
	 *
	 * @param mixed $url Raw address.
	 * @return string|null
	 */
	public static function page_url( $url ) {
		return is_string( $url ) && strlen( $url ) <= 2000 && preg_match( '~^https?://[^\s<>"]+$~i', $url ) ? $url : null;
	}

	/**
	 * A host name (IP addresses and single-label hosts such as "localhost" included).
	 *
	 * @param mixed $host Raw host.
	 * @return string|null
	 */
	public static function host( $host ) {
		$host = is_string( $host ) ? strtolower( $host ) : '';
		return strlen( $host ) <= 253 && preg_match( '/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $host ) ? $host : null;
	}

	/**
	 * A page address item: the normalised address or an error code.
	 *
	 * @param mixed $url Raw address.
	 * @return string
	 */
	public static function page( $url ) {
		$clean = self::page_url( $url );
		return null === $clean ? 'bad_field' : $clean;
	}

	/**
	 * A host item.
	 *
	 * @param mixed $host Raw host.
	 * @return string
	 */
	public static function host_item( $host ) {
		$clean = self::host( $host );
		return null === $clean ? 'bad_field' : $clean;
	}

	/**
	 * One cookie: metadata only.
	 *
	 * @param mixed $c Raw cookie.
	 * @return array|string
	 */
	public static function cookie( $c ) {
		if ( ! is_array( $c ) ) {
			return 'bad_field';
		}
		$unknown = self::unknown_key( $c, array( 'name', 'domain', 'path', 'httpOnly', 'secure', 'sameSite', 'expires' ) );
		if ( null !== $unknown ) {
			return 'unknown_field:' . $unknown;
		}
		$domain = isset( $c['domain'] ) && is_string( $c['domain'] ) ? strtolower( $c['domain'] ) : '';
		$expiry = isset( $c['expires'] ) ? $c['expires'] : -1;
		$valid  = isset( $c['name'] ) && is_string( $c['name'] ) && '' !== $c['name'] && strlen( $c['name'] ) <= 200
			&& '' !== $domain && strlen( $domain ) <= 255 && preg_match( '/^\.?[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $domain )
			&& ( ! isset( $c['path'] ) || ( is_string( $c['path'] ) && strlen( $c['path'] ) <= 255 ) )
			&& ( ! isset( $c['httpOnly'] ) || is_bool( $c['httpOnly'] ) ) && ( ! isset( $c['secure'] ) || is_bool( $c['secure'] ) )
			&& ( ! isset( $c['sameSite'] ) || in_array( $c['sameSite'], self::SAME_SITE, true ) )
			&& ( is_int( $expiry ) || is_float( $expiry ) ) && $expiry >= -1 && $expiry < 41024448000;
		if ( ! $valid ) {
			return 'bad_field';
		}
		return array(
			'name'      => $c['name'],
			'domain'    => $domain,
			'path'      => isset( $c['path'] ) ? $c['path'] : '/',
			'http_only' => ! empty( $c['httpOnly'] ),
			'secure'    => ! empty( $c['secure'] ),
			'same_site' => isset( $c['sameSite'] ) ? $c['sameSite'] : 'None',
			'expires'   => (int) $expiry,
		);
	}

	/**
	 * A web storage key together with the origin that holds it.
	 *
	 * @param mixed $entry Raw entry {origin, key}.
	 * @return array|string
	 */
	public static function storage_entry( $entry ) {
		if ( ! is_array( $entry ) ) {
			return 'bad_field';
		}
		$unknown = self::unknown_key( $entry, array( 'origin', 'key' ) );
		if ( null !== $unknown ) {
			return 'unknown_field:' . $unknown;
		}
		if ( ! isset( $entry['origin'], $entry['key'] ) || ! is_string( $entry['key'] ) || '' === $entry['key'] || strlen( $entry['key'] ) > 200 || null === self::page_url( $entry['origin'] ) || strlen( $entry['origin'] ) > 300 ) {
			return 'bad_field';
		}
		return array(
			'origin' => $entry['origin'],
			'key'    => $entry['key'],
		);
	}

	/**
	 * Names found in Set-Cookie headers of one host.
	 *
	 * @param mixed $entry Raw entry {host, names}.
	 * @return array|string
	 */
	public static function set_cookie( $entry ) {
		if ( ! is_array( $entry ) ) {
			return 'bad_field';
		}
		$unknown = self::unknown_key( $entry, array( 'host', 'names' ) );
		if ( null !== $unknown ) {
			return 'unknown_field:' . $unknown;
		}
		$host = isset( $entry['host'] ) ? self::host( $entry['host'] ) : null;
		if ( null === $host || ! isset( $entry['names'] ) || ! is_array( $entry['names'] ) || count( $entry['names'] ) > 100 ) {
			return 'bad_field';
		}
		foreach ( $entry['names'] as $name ) {
			if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 200 ) {
				return 'bad_field';
			}
		}
		return array(
			'host'  => $host,
			'names' => array_values( $entry['names'] ),
		);
	}
}
