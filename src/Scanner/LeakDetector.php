<?php
/**
 * Finds "leaks": an analytics or marketing cookie (or storage key) present in a browser whose reported consent denies
 * that category, or that vendor. Pure: no WordPress calls.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

final class LeakDetector {

	/** Categories a leak can be reported for. Necessary and functional need no consent; unclassified is reported separately. */
	const WATCHED = array( 'analytics', 'marketing' );

	/**
	 * Filter findings down to the ones the reported consent does not allow.
	 *
	 * @param array[] $findings Entries with name, storage, category, service_slug.
	 * @param array   $consent  Reported consent: {c: {analytics, marketing...}, s: {slug: 0|1}}.
	 * @return array[] The leaking findings.
	 */
	public static function find( array $findings, array $consent ) {
		$cats = isset( $consent['c'] ) && is_array( $consent['c'] ) ? $consent['c'] : array();
		$svc  = isset( $consent['s'] ) && is_array( $consent['s'] ) ? $consent['s'] : array();
		$out  = array();
		foreach ( $findings as $finding ) {
			$category = isset( $finding['category'] ) ? $finding['category'] : '';
			if ( isset( $finding['status'] ) && 'ignored' === $finding['status'] ) {
				continue; // The admin decided to ignore this cookie.
			}
			if ( ! in_array( $category, self::WATCHED, true ) ) {
				continue;
			}
			$slug      = isset( $finding['service_slug'] ) ? (string) $finding['service_slug'] : '';
			$cat_on    = ! empty( $cats[ $category ] );
			$vendor_ok = '' === $slug || ! array_key_exists( $slug, $svc ) || ! empty( $svc[ $slug ] );
			if ( ! $cat_on || ! $vendor_ok ) {
				$out[] = $finding;
			}
		}
		return $out;
	}
}
