<?php
/**
 * Human readable cookie lifetime ("Phiên", "3 tháng", "2 năm"...) for cookies the known database does not describe.
 * Pure: no WordPress calls.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

final class DurationLabel {

	/** Unit seconds, largest first: [seconds, vi, en singular, en plural]. */
	const UNITS = array(
		array( 31536000, 'năm', 'year', 'years' ),
		array( 2592000, 'tháng', 'month', 'months' ),
		array( 86400, 'ngày', 'day', 'days' ),
		array( 3600, 'giờ', 'hour', 'hours' ),
		array( 60, 'phút', 'minute', 'minutes' ),
	);

	/**
	 * Label for a lifetime in seconds; null or 0 = session cookie.
	 *
	 * @param int|null $seconds Lifetime.
	 * @param string   $lang    vi|en.
	 * @return string
	 */
	public static function label( $seconds, $lang = 'vi' ) {
		$en = 'en' === $lang;
		if ( null === $seconds || (int) $seconds <= 0 ) {
			return $en ? 'Session' : 'Phiên';
		}
		foreach ( self::UNITS as $unit ) {
			if ( (int) $seconds >= $unit[0] ) {
				$n = max( 1, (int) round( (int) $seconds / $unit[0] ) );
				return $en ? $n . ' ' . ( 1 === $n ? $unit[2] : $unit[3] ) : $n . ' ' . $unit[1];
			}
		}
		return $en ? '1 minute' : '1 phút';
	}
}
