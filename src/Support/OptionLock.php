<?php
/**
 * Small cross-request lock kept in an option row. add_option() fails when the row exists, which makes the claim
 * atomic enough for "only one request runs this job at a time". A lock older than its lifetime belongs to a request
 * that died and is taken over.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Support;

defined( 'ABSPATH' ) || exit;

final class OptionLock {

	/**
	 * Try to claim the lock.
	 *
	 * @param string $name Option name.
	 * @param int    $ttl  Seconds after which an existing lock is considered abandoned.
	 * @return bool True when the caller now holds the lock.
	 */
	public static function acquire( $name, $ttl ) {
		if ( add_option( $name, (string) time(), '', false ) ) {
			return true;
		}
		$started = (int) get_option( $name );
		if ( $started > 0 && time() - $started < (int) $ttl ) {
			return false;
		}
		delete_option( $name );
		return (bool) add_option( $name, (string) time(), '', false );
	}

	/**
	 * Release the lock.
	 *
	 * @param string $name Option name.
	 */
	public static function release( $name ) {
		delete_option( $name );
	}
}
