<?php
/**
 * Fixed-window rate limiter backed by transients.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

defined( 'ABSPATH' ) || exit;

final class RateLimiter {

	/**
	 * Register one hit; returns false when the bucket is already full.
	 *
	 * @param string $bucket Opaque identity (for example the masked IP).
	 * @param int    $max    Maximum hits per window.
	 * @param int    $window Window length in seconds.
	 * @return bool True when the hit is allowed.
	 */
	public static function allow( $bucket, $max = 10, $window = 60 ) {
		$key   = 'dscc_rl_' . md5( (string) $bucket );
		$now   = time();
		$state = get_transient( $key );
		if ( ! is_array( $state ) || ! isset( $state['n'], $state['t'] ) || $now - (int) $state['t'] >= $window ) {
			$state = array(
				'n' => 0,
				't' => $now,
			);
		}
		if ( (int) $state['n'] >= $max ) {
			return false;
		}
		$state['n'] = (int) $state['n'] + 1;
		set_transient( $key, $state, $window );
		return true;
	}
}
