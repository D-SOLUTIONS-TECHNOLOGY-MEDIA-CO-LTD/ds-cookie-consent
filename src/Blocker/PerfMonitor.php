<?php
/**
 * Timing of the output buffer work. A slow page (over 30 ms) is recorded for the admin, at most once per
 * sampling window so a slow site does not write to the database on every request.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

defined( 'ABSPATH' ) || exit;

final class PerfMonitor {

	/** Not autoloaded: it is only read on a slow request and in the admin. */
	const OPTION = 'dscc_blocker_perf';

	const THRESHOLD_MS = 30;

	/** One write per this many seconds. */
	const SAMPLE_WINDOW = 600;

	/** A warning older than this is not shown any more. */
	const MAX_AGE = 604800;

	/**
	 * Record a measurement when it is over the threshold and the sampling window allows.
	 *
	 * @param float  $ms     Milliseconds spent.
	 * @param int    $bytes  Size of the page.
	 * @param string $path   Request path (no query string).
	 * @param int    $now    Unix time.
	 * @return bool True when a record was written.
	 */
	public static function record( $ms, $bytes, $path, $now = 0 ) {
		$limit = (int) apply_filters( 'dscc_blocker_slow_ms', self::THRESHOLD_MS );
		if ( $ms <= $limit ) {
			return false;
		}
		$now      = $now ? (int) $now : time();
		$existing = get_option( self::OPTION );
		if ( is_array( $existing ) && isset( $existing['time'] ) && $now - (int) $existing['time'] < self::SAMPLE_WINDOW && (float) $existing['ms'] >= $ms ) {
			return false;
		}
		update_option(
			self::OPTION,
			array(
				'ms'    => round( (float) $ms, 1 ),
				'bytes' => (int) $bytes,
				'path'  => substr( (string) $path, 0, 120 ),
				'time'  => $now,
			),
			false
		);
		return true;
	}

	/**
	 * The stored warning, or null when none exists or it is stale.
	 *
	 * @return array|null
	 */
	public static function warning() {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['time'] ) || time() - (int) $stored['time'] > self::MAX_AGE ) {
			return null;
		}
		return $stored;
	}

	/**
	 * Forget the warning (the admin changed the setup and wants a fresh measurement).
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
