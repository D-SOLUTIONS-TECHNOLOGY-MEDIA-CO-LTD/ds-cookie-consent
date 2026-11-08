<?php
/**
 * Data access for catalog cookies (cookie, localStorage, sessionStorage... entries). Every write fires
 * `dscc_catalog_changed`. A cookie's category is the category of its service (no service = unclassified).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

use DSolutions\CookieConsent\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class CookieRepository {

	const STORAGES = array( 'cookie', 'localStorage', 'sessionStorage', 'indexedDB', 'pixel' );
	const STATUSES = array( 'auto', 'confirmed', 'ignored', 'new' );
	const SOURCES  = array( 'known_db', 'scan', 'manual', 'deep_scan' );
	const SET_BY   = array( 'server', 'client', 'unknown' );

	/** Writable columns (id, timestamps and the seen_* counters are managed here or by the scanner). */
	const FIELDS = array( 'service_id', 'name', 'storage', 'domain', 'path', 'duration_label', 'duration_label_en', 'duration_seconds', 'purpose', 'purpose_en', 'http_only', 'set_by', 'status', 'source', 'table_group', 'applies_to', 'applies_to_en' );

	/**
	 * One cookie by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = Schema::cookies_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $row ? CookieRow::hydrate( $row ) : null;
	}

	/**
	 * One cookie by its identity (name, storage, domain).
	 *
	 * @param string $name    Name.
	 * @param string $storage Storage kind.
	 * @param string $domain  Domain ('' = unspecified).
	 * @return array|null
	 */
	public static function find_identity( $name, $storage = 'cookie', $domain = '' ) {
		global $wpdb;
		$table = Schema::cookies_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s AND storage = %s AND domain = %s", (string) $name, (string) $storage, (string) $domain ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $row ? CookieRow::hydrate( $row ) : null;
	}

	/**
	 * Cookies of one service, ordered by name.
	 *
	 * @param int    $service_id Service id.
	 * @param string $status     Status filter ('' = any).
	 * @return array[]
	 */
	public static function for_service( $service_id, $status = '' ) {
		global $wpdb;
		$table = Schema::cookies_table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE service_id = %d", (int) $service_id ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		if ( '' !== $status ) {
			$sql .= $wpdb->prepare( ' AND status = %s', $status );
		}
		$rows = $wpdb->get_results( $sql . ' ORDER BY name ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- both parts are prepared above.
		return array_map( array( CookieRow::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Insert a cookie, or update the one with the same (name, storage, domain). Only the fields present in
	 * $data change on update. A name containing "*" is stored as a pattern.
	 *
	 * @param array $data Field values (see FIELDS); name is required.
	 * @return int Cookie id, 0 on invalid input or failure.
	 */
	public static function upsert( array $data ) {
		global $wpdb;
		$clean = CookieRow::sanitize( $data );
		if ( '' === $clean['name'] ) {
			return 0;
		}
		$table    = Schema::cookies_table();
		$existing = self::find_identity( $clean['name'], isset( $clean['storage'] ) ? $clean['storage'] : 'cookie', isset( $clean['domain'] ) ? $clean['domain'] : '' );
		$now      = current_time( 'mysql', true );

		if ( $existing ) {
			$given = array_diff_key( array_intersect_key( $clean, $data ), array_flip( array( 'name', 'storage', 'domain' ) ) );
			if ( $given ) {
				$given['updated_at'] = $now;
				$wpdb->update( $table, $given, array( 'id' => $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			}
			$id = (int) $existing['id'];
		} else {
			$row               = array_merge( CookieRow::defaults(), $clean );
			$row['created_at'] = $now;
			$row['updated_at'] = $now;
			if ( ! $wpdb->insert( $table, $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
				return 0;
			}
			$id = (int) $wpdb->insert_id;
		}
		do_action( 'dscc_catalog_changed' );
		return $id;
	}

	/**
	 * Update selected fields of one cookie by id (name, storage and domain stay as they are).
	 *
	 * @param int   $id     Cookie id.
	 * @param array $fields Fields to change.
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$cookie = self::find( $id );
		if ( ! $cookie ) {
			return false;
		}
		$clean = array_diff_key( array_intersect_key( CookieRow::sanitize( array_merge( $fields, array( 'name' => $cookie['name'] ) ) ), $fields ), array_flip( array( 'name', 'storage', 'domain' ) ) );
		if ( $clean ) {
			$clean['updated_at'] = current_time( 'mysql', true );
			$wpdb->update( Schema::cookies_table(), $clean, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			do_action( 'dscc_catalog_changed' );
		}
		return true;
	}

	/**
	 * Set the status of several cookies.
	 *
	 * @param int[]  $ids    Ids.
	 * @param string $status New status.
	 * @return int Rows changed.
	 */
	public static function set_status_many( array $ids, $status ) {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( ! $ids || ! in_array( $status, self::STATUSES, true ) ) {
			return 0;
		}
		$table = Schema::cookies_table();
		$in    = implode( ',', $ids );
		$n     = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, updated_at = %s WHERE id IN ({$in})", $status, current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are integers.
		do_action( 'dscc_catalog_changed' );
		return (int) $n;
	}

	/**
	 * Delete several cookies.
	 *
	 * @param int[] $ids Ids.
	 * @return int Rows deleted.
	 */
	public static function delete_many( array $ids ) {
		global $wpdb;
		$ids = self::ids( $ids );
		if ( ! $ids ) {
			return 0;
		}
		$table = Schema::cookies_table();
		$n     = $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- ids are integers.
		do_action( 'dscc_catalog_changed' );
		return (int) $n;
	}

	/**
	 * Cookies waiting for review (status new). Cached for the admin menu badge until the catalog changes.
	 *
	 * @return int
	 */
	public static function count_new() {
		if ( get_option( 'dscc_db_version' ) !== Schema::VERSION ) {
			return 0; // Tables are created by the upgrade routine on admin_init, which runs after the menu is built.
		}
		$cached = get_transient( 'dscc_new_cookies' );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		global $wpdb;
		$table = Schema::cookies_table();
		$n     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", 'new' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		set_transient( 'dscc_new_cookies', $n, HOUR_IN_SECONDS );
		return $n;
	}

	/**
	 * Confirmed rows of the site whose stored name equals or matches (pattern) an observed name.
	 *
	 * @param string $name    Observed name.
	 * @param string $storage Storage kind.
	 * @return array|null First match, exact names before patterns.
	 */
	public static function match_confirmed( $name, $storage = 'cookie' ) {
		global $wpdb;
		$table = Schema::cookies_table();
		$exact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE name = %s AND storage = %s AND status = 'confirmed' LIMIT 1", (string) $name, (string) $storage ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		if ( $exact ) {
			return CookieRow::hydrate( $exact );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE name_is_pattern = 1 AND storage = %s AND status = 'confirmed'", (string) $storage ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( PatternMatcher::name_matches( $row['name'], $name ) ) {
				return CookieRow::hydrate( $row );
			}
		}
		return null;
	}

	/**
	 * Integer ids only, unique.
	 *
	 * @param array $ids Raw ids.
	 * @return int[]
	 */
	private static function ids( array $ids ) {
		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}
}
