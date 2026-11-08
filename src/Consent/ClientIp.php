<?php
/**
 * Determines the client IP for masking and rate limiting. REMOTE_ADDR is used unless the admin names a
 * trusted proxy header (advanced.trusted_ip_header); a spoofable header is never read by default.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

final class ClientIp {

	/** Setting value => $_SERVER key. */
	const HEADERS = array(
		'cf-connecting-ip' => 'HTTP_CF_CONNECTING_IP',
		'x-forwarded-for'  => 'HTTP_X_FORWARDED_FOR',
	);

	/**
	 * Pick the client IP from server variables.
	 *
	 * @param array  $server Server variables ($_SERVER).
	 * @param string $mode   none|cf-connecting-ip|x-forwarded-for.
	 * @return string A valid IP address, or '' when none.
	 */
	public static function pick( array $server, $mode ) {
		if ( isset( self::HEADERS[ $mode ] ) ) {
			$key = self::HEADERS[ $mode ];
			if ( isset( $server[ $key ] ) && is_string( $server[ $key ] ) ) {
				// X-Forwarded-For lists "client, proxy1, proxy2": the first entry is the client.
				$parts = explode( ',', $server[ $key ] );
				$ip    = trim( $parts[0] );
				if ( false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		$remote = isset( $server['REMOTE_ADDR'] ) && is_string( $server['REMOTE_ADDR'] ) ? trim( $server['REMOTE_ADDR'] ) : '';
		return false !== filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	}
}
