<?php
/**
 * Cheap request validation for the public, cache-friendly consent endpoints.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

final class RequestGuard {

	/** Maximum accepted request body, in bytes. */
	const MAX_BODY_BYTES = 4096;

	/**
	 * True when the URL host is one of the allowed hosts.
	 *
	 * @param string   $url     Origin or Referer value.
	 * @param string[] $allowed Allowed host names.
	 * @return bool
	 */
	public static function same_host( $url, array $allowed ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return false;
		}
		$host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper, no WP dependency.
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		$host = strtolower( $host );
		foreach ( $allowed as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate && strtolower( $candidate ) === $host ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Origin wins when present; otherwise Referer. A request with neither header is rejected.
	 *
	 * @param string   $origin  Origin header.
	 * @param string   $referer Referer header.
	 * @param string[] $allowed Allowed host names.
	 * @return bool
	 */
	public static function origin_allowed( $origin, $referer, array $allowed ) {
		if ( is_string( $origin ) && '' !== $origin && 'null' !== $origin ) {
			return self::same_host( $origin, $allowed );
		}
		if ( is_string( $referer ) && '' !== $referer ) {
			return self::same_host( $referer, $allowed );
		}
		return false;
	}

	/**
	 * Body size limit (4 KB).
	 *
	 * @param int $bytes Body length.
	 * @return bool
	 */
	public static function body_within_limit( $bytes ) {
		return is_numeric( $bytes ) && (int) $bytes >= 0 && (int) $bytes <= self::MAX_BODY_BYTES;
	}
}
