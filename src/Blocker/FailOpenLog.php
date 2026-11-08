<?php
/**
 * Records why the blocker let a page through unchanged (a PCRE failure, an exception, a tag too large to parse, an
 * earlier flush that split the page). Without it those pages would run trackers before consent with no sign.
 * Bounded: one small non-autoloaded option, written at most once per window unless the reason changes.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

defined( 'ABSPATH' ) || exit;

final class FailOpenLog {

	const OPTION  = 'dscc_blocker_failopen';
	const WINDOW  = 600;
	const MAX_AGE = 604800;

	const REASONS = array( 'pcre', 'exception', 'unparsed_tag', 'partial_flush' );

	/**
	 * Record a fail-open event.
	 *
	 * @param string $reason One of REASONS.
	 * @param string $path   Request path.
	 * @param int    $now    Unix time (tests).
	 * @return bool True when the option was written.
	 */
	public static function record( $reason, $path, $now = 0 ) {
		$reason = in_array( $reason, self::REASONS, true ) ? $reason : 'exception';
		$path   = substr( (string) $path, 0, 120 );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[ds-cookie-consent] blocker let a page through unchanged: ' . $reason . ' (' . $path . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- opt-in debugging aid.
		}
		$now      = $now ? (int) $now : time();
		$existing = get_option( self::OPTION );
		if ( is_array( $existing ) && isset( $existing['time'], $existing['reason'] ) && $existing['reason'] === $reason && $now - (int) $existing['time'] < self::WINDOW ) {
			return false;
		}
		update_option(
			self::OPTION,
			array(
				'reason' => $reason,
				'path'   => $path,
				'time'   => $now,
			),
			false
		);
		return true;
	}

	/**
	 * The latest record, or null when there is none or it is a week old.
	 *
	 * @return array|null
	 */
	public static function latest() {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['time'] ) || time() - (int) $stored['time'] > self::MAX_AGE ) {
			return null;
		}
		return $stored;
	}

	/**
	 * Forget the record.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
