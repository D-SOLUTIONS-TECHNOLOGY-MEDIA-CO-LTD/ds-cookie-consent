<?php
/**
 * Anonymises client IP addresses before they are stored (IPv4 /24, IPv6 /48).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

final class IpMasker {

	/**
	 * Mask an IP address. Returns an empty string for anything that is not a valid IP.
	 *
	 * @param string $ip Raw IP.
	 * @return string
	 */
	public static function mask( $ip ) {
		if ( ! is_string( $ip ) || '' === $ip ) {
			return '';
		}
		$packed = @inet_pton( trim( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid input is handled below.
		if ( false === $packed ) {
			return '';
		}
		if ( 16 === strlen( $packed ) ) {
			// IPv4-mapped IPv6 (::ffff:a.b.c.d) is treated as IPv4.
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
				$packed = substr( $packed, 12 );
			} else {
				$masked = substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );
				return (string) inet_ntop( $masked );
			}
		}
		// IPv4: zero the last octet.
		$masked = substr( $packed, 0, 3 ) . "\0";
		return (string) inet_ntop( $masked );
	}
}
