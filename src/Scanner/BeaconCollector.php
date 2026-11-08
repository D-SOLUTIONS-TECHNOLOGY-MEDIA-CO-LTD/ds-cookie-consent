<?php
/**
 * Merges one validated beacon report (cookie names, storage keys and resource hosts seen in a real visitor's browser)
 * into the catalog, and turns names that the visitor's own consent forbids into leak alerts.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Catalog\KnownDb;

defined( 'ABSPATH' ) || exit;

final class BeaconCollector {

	/** New catalog rows one report may create, and all reports together per hour (a spoofed flood cannot grow the table). */
	const MAX_NEW_PER_REPORT = 10;
	const MAX_NEW_PER_HOUR   = 30;
	const BUDGET_TRANSIENT   = 'dscc_beacon_new';

	/**
	 * Ingest a report.
	 *
	 * @param array $data Output of BeaconValidator::validate()['data'].
	 * @return array {merged: int, created: int, leaks: int, detected: int}
	 */
	public static function ingest( array $data ) {
		$observations = array();
		$groups       = array(
			'cookie'         => $data['cookies'],
			'localStorage'   => $data['local'],
			'sessionStorage' => $data['session'],
		);
		foreach ( $groups as $storage => $names ) {
			foreach ( $names as $name ) {
				$observations[] = array(
					'name'    => $name,
					'storage' => $storage,
					'set_by'  => 'client',
					'url'     => $data['path'],
				);
			}
		}
		$budget  = max( 0, self::MAX_NEW_PER_HOUR - (int) get_transient( self::BUDGET_TRANSIENT ) );
		$results = FindingStore::merge_cookies(
			$observations,
			array(
				'source'  => 'scan',
				'via'     => 'beacon',
				'max_new' => min( self::MAX_NEW_PER_REPORT, $budget ),
			)
		);
		$created = 0;
		foreach ( $results as $result ) {
			if ( $result['created'] ) {
				++$created;
			}
		}
		if ( $created ) {
			set_transient( self::BUDGET_TRANSIENT, (int) get_transient( self::BUDGET_TRANSIENT ) + $created, HOUR_IN_SECONDS );
		}

		$leaks = LeakDetector::find( $results, $data['consent'] );
		AlertStore::record_leaks( $leaks, 'beacon' );

		return array(
			'merged'   => count( $results ),
			'created'  => $created,
			'leaks'    => count( $leaks ),
			'detected' => self::detect_hosts( $data['hosts'] ),
		);
	}

	/**
	 * Record platforms whose hosts the browser loaded resources from.
	 *
	 * @param string[] $hosts Resource hosts.
	 * @return int Platforms noted.
	 */
	private static function detect_hosts( array $hosts ) {
		$known = KnownDb::load();
		if ( ! $hosts || ! $known ) {
			return 0;
		}
		$resources = array();
		foreach ( $hosts as $host ) {
			$resources[] = array(
				'kind'   => 'script',
				'src'    => 'https://' . $host . '/',
				'inline' => '',
			);
		}
		$found = HtmlScanner::detect( $resources, $known, '' );
		$n     = 0;
		foreach ( array_keys( $found['services'] ) as $slug ) {
			if ( FindingStore::note_detection( $slug, 'beacon' ) ) {
				++$n;
			}
		}
		return $n;
	}
}
