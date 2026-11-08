<?php
/**
 * Parses one Set-Cookie header value into the attributes the scanner records. The cookie VALUE is dropped on purpose:
 * the plugin never stores or transmits cookie values. Pure: no WordPress calls.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

final class SetCookieParser {

	/**
	 * Parse a header value ("name=value; Path=/; Max-Age=3600; HttpOnly"), with or without the "Set-Cookie:" prefix.
	 *
	 * @param string   $header Header value.
	 * @param int|null $now    Unix time used for Expires (default: now).
	 * @return array|null {name, domain, path, max_age (seconds, null = session), http_only, secure, same_site, deleted}
	 *                    or null when the header is not a usable cookie.
	 */
	public static function parse( $header, $now = null ) {
		$header = trim( (string) $header );
		if ( 0 === stripos( $header, 'set-cookie:' ) ) {
			$header = trim( substr( $header, 11 ) );
		}
		if ( '' === $header || strlen( $header ) > 8192 ) {
			return null;
		}
		$parts = explode( ';', $header );
		$first = (string) array_shift( $parts );
		$eq    = strpos( $first, '=' );
		if ( false === $eq ) {
			return null;
		}
		$name = rawurldecode( trim( substr( $first, 0, $eq ) ) );
		if ( '' === $name || strlen( $name ) > 100 || preg_match( '/[\x00-\x20\x7F"\'<>\\\\]/', $name ) ) {
			return null;
		}
		$out = array(
			'name'      => $name,
			'domain'    => '',
			'path'      => '/',
			'max_age'   => null,
			'http_only' => false,
			'secure'    => false,
			'same_site' => '',
			'deleted'   => false,
		);
		$now = null === $now ? time() : (int) $now;
		$max = null;
		$exp = null;
		foreach ( $parts as $part ) {
			$kv    = explode( '=', $part, 2 );
			$key   = strtolower( trim( $kv[0] ) );
			$value = isset( $kv[1] ) ? trim( $kv[1] ) : '';
			switch ( $key ) {
				case 'domain':
					$out['domain'] = strtolower( preg_replace( '/[^A-Za-z0-9.\-]/', '', $value ) );
					break;
				case 'path':
					$out['path'] = '' === $value ? '/' : substr( $value, 0, 100 );
					break;
				case 'max-age':
					if ( preg_match( '/^-?\d+$/', $value ) ) {
						$max = (int) $value;
					}
					break;
				case 'expires':
					$time = strtotime( $value );
					if ( false !== $time ) {
						$exp = (int) $time - $now;
					}
					break;
				case 'httponly':
					$out['http_only'] = true;
					break;
				case 'secure':
					$out['secure'] = true;
					break;
				case 'samesite':
					$out['same_site'] = strtolower( $value );
					break;
			}
		}
		// Max-Age wins over Expires (RFC 6265).
		$life = null !== $max ? $max : $exp;
		if ( null !== $life ) {
			if ( $life <= 0 ) {
				$out['deleted'] = true;
			} else {
				$out['max_age'] = $life;
			}
		}
		return $out;
	}
}
