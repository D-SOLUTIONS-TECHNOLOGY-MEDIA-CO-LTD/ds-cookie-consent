<?php
/**
 * Stored "leak" alerts: an analytics or marketing cookie seen in a browser whose consent denies it. Fed by the beacon
 * collector and by the headless deep scan. A small non-autoloaded option; entries expire after 30 days without a
 * new sighting and the list never holds more than MAX_LEAKS entries.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

defined( 'ABSPATH' ) || exit;

final class AlertStore {

	const OPTION    = 'dscc_alerts';
	const MAX_LEAKS = 50;
	const TTL       = 2592000;

	/**
	 * Record leaking findings.
	 *
	 * @param array[] $leaks  Entries with name, storage, category, service_slug.
	 * @param string  $source beacon|deep.
	 * @param int     $now    Unix time (default now).
	 */
	public static function record_leaks( array $leaks, $source, $now = 0 ) {
		if ( ! $leaks ) {
			return;
		}
		$now    = $now ? (int) $now : time();
		$stored = self::prune( self::read(), $now );
		foreach ( $leaks as $leak ) {
			$storage = isset( $leak['storage'] ) ? (string) $leak['storage'] : 'cookie';
			$key     = $storage . ':' . $leak['name'];
			if ( isset( $stored['leaks'][ $key ] ) ) {
				$stored['leaks'][ $key ]['count']  = (int) $stored['leaks'][ $key ]['count'] + 1;
				$stored['leaks'][ $key ]['last']   = $now;
				$stored['leaks'][ $key ]['source'] = (string) $source;
				continue;
			}
			$stored['leaks'][ $key ] = array(
				'name'     => (string) $leak['name'],
				'storage'  => $storage,
				'category' => (string) $leak['category'],
				'service'  => isset( $leak['service_slug'] ) ? (string) $leak['service_slug'] : '',
				'source'   => (string) $source,
				'count'    => 1,
				'first'    => $now,
				'last'     => $now,
			);
		}
		uasort(
			$stored['leaks'],
			static function ( $a, $b ) {
				return $b['last'] - $a['last'];
			}
		);
		$stored['leaks'] = array_slice( $stored['leaks'], 0, self::MAX_LEAKS, true );
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Current leak alerts, newest first.
	 *
	 * @return array[]
	 */
	public static function leaks() {
		return array_values( self::prune( self::read(), time() )['leaks'] );
	}

	/**
	 * Forget every alert (the admin fixed the cause and wants a clean slate).
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Forget the alerts last reported by one source (a new deep scan replaces the previous deep scan's findings).
	 *
	 * @param string $source beacon|deep.
	 */
	public static function clear_source( $source ) {
		$stored = self::read();
		foreach ( $stored['leaks'] as $key => $leak ) {
			if ( is_array( $leak ) && isset( $leak['source'] ) && (string) $source === $leak['source'] ) {
				unset( $stored['leaks'][ $key ] );
			}
		}
		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Stored value with the expected shape.
	 *
	 * @return array{leaks: array}
	 */
	private static function read() {
		$stored = get_option( self::OPTION );
		return array(
			'leaks' => is_array( $stored ) && isset( $stored['leaks'] ) && is_array( $stored['leaks'] ) ? $stored['leaks'] : array(),
		);
	}

	/**
	 * Drop expired entries.
	 *
	 * @param array $stored Stored value.
	 * @param int   $now    Unix time.
	 * @return array
	 */
	private static function prune( array $stored, $now ) {
		foreach ( $stored['leaks'] as $key => $leak ) {
			if ( ! is_array( $leak ) || ! isset( $leak['last'], $leak['name'] ) || $now - (int) $leak['last'] > self::TTL ) {
				unset( $stored['leaks'][ $key ] );
			}
		}
		return $stored;
	}
}
