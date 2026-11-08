<?php
/**
 * Daily count of log requests refused by the rate limiter (shown on the overview screen).
 * Kept as a small non-autoloaded option: date (UTC) => count, last 7 days.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

defined( 'ABSPATH' ) || exit;

final class RateLimitStats {

	const OPTION = 'dscc_blocked_daily';
	const DAYS   = 7;

	/**
	 * Count one refused request for today.
	 */
	public static function record() {
		$days         = self::all();
		$day          = gmdate( 'Y-m-d' );
		$days[ $day ] = ( isset( $days[ $day ] ) ? $days[ $day ] : 0 ) + 1;
		krsort( $days );
		update_option( self::OPTION, array_slice( $days, 0, self::DAYS, true ), false );
	}

	/**
	 * Counts per day (UTC date => count), newest first, restricted to the last 7 days.
	 *
	 * @return array<string,int>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$out    = array();
		if ( is_array( $stored ) ) {
			$oldest = gmdate( 'Y-m-d', time() - ( self::DAYS - 1 ) * DAY_IN_SECONDS );
			foreach ( $stored as $day => $count ) {
				if ( is_string( $day ) && $day >= $oldest && is_numeric( $count ) ) {
					$out[ $day ] = (int) $count;
				}
			}
		}
		krsort( $out );
		return $out;
	}

	/**
	 * Refused requests today.
	 *
	 * @return int
	 */
	public static function today() {
		$days = self::all();
		$day  = gmdate( 'Y-m-d' );
		return isset( $days[ $day ] ) ? $days[ $day ] : 0;
	}

	/**
	 * Refused requests over the last 7 days.
	 *
	 * @return int
	 */
	public static function week() {
		return (int) array_sum( self::all() );
	}
}
