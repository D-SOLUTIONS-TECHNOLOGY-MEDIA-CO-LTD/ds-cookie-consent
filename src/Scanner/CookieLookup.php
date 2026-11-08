<?php
/**
 * Read-only lookup of catalog cookie rows by observed name (exact before pattern, best status first) and the
 * classification of names without writing anything.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Blocker\CookieSafelist;
use DSolutions\CookieConsent\Catalog\Classifier;
use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Catalog\PatternMatcher;
use DSolutions\CookieConsent\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class CookieLookup {

	/**
	 * Rows that may match any of the names: same name (any domain) or a pattern row, joined with their service.
	 *
	 * @param string[] $names   Names.
	 * @param string   $storage Storage kind.
	 * @return array[]
	 */
	public static function rows( array $names, $storage ) {
		global $wpdb;
		if ( ! $names ) {
			return array();
		}
		$cookies  = Schema::cookies_table();
		$services = Schema::services_table();
		$holders  = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$sql      = "SELECT c.*, s.slug AS service_slug, COALESCE(s.category_key, 'unclassified') AS category, s.status AS service_status FROM {$cookies} c LEFT JOIN {$services} s ON s.id = c.service_id WHERE c.storage = %s AND (c.name IN ({$holders}) OR c.name_is_pattern = 1)";
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( (string) $storage ), array_values( $names ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom tables; placeholders are generated above.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The row for a name: exact name first (best status wins), then patterns.
	 *
	 * @param array[] $rows Rows from load_rows().
	 * @param string  $name Observed name.
	 * @return array|null
	 */
	public static function find( array $rows, $name ) {
		$best = null;
		foreach ( $rows as $row ) {
			if ( (int) $row['name_is_pattern'] ) {
				continue;
			}
			if ( $row['name'] === $name && ( null === $best || FindingStore::STATUS_RANK[ $row['status'] ] > FindingStore::STATUS_RANK[ $best['status'] ] ) ) {
				$best = $row;
			}
		}
		if ( $best ) {
			return $best;
		}
		foreach ( $rows as $row ) {
			if ( (int) $row['name_is_pattern'] && PatternMatcher::name_matches( $row['name'], $name ) && ( null === $best || FindingStore::STATUS_RANK[ $row['status'] ] > FindingStore::STATUS_RANK[ $best['status'] ] ) ) {
				$best = $row;
			}
		}
		return $best;
	}

	/**
	 * Classify names without writing anything (the compliance report and tests).
	 *
	 * @param string[] $names   Names.
	 * @param string   $storage Storage kind.
	 * @return array<string,array> name => {category, service_slug, status, id}
	 */
	public static function resolve( array $names, $storage = 'cookie' ) {
		$names      = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );
		$rows       = self::rows( $names, $storage );
		$known      = KnownDb::load();
		$classifier = new Classifier(
			$known,
			static function () {
				return null;
			}
		);
		$safe       = CookieSafelist::patterns();
		$out        = array();
		foreach ( $names as $name ) {
			$row = self::find( $rows, $name );
			if ( $row ) {
				$out[ $name ] = array(
					'category'     => $row['category'],
					'service_slug' => (string) $row['service_slug'],
					'status'       => $row['status'],
					'id'           => (int) $row['id'],
				);
				continue;
			}
			$cls = $classifier->classify(
				array(
					'name'    => $name,
					'storage' => $storage,
				)
			);
			if ( CookieSafelist::matches( $name, $safe ) && ! in_array( $cls['category'], array( 'necessary', 'functional' ), true ) ) {
				$out[ $name ] = array(
					'category'     => 'necessary',
					'service_slug' => FindingStore::HOLDER_NECESSARY,
					'status'       => 'auto',
					'id'           => 0,
				);
				continue;
			}
			$out[ $name ] = array(
				'category'     => $cls['category'],
				'service_slug' => $cls['service_slug'],
				'status'       => 'unclassified' === $cls['category'] ? 'new' : 'auto',
				'id'           => 0,
			);
		}
		return $out;
	}
}
