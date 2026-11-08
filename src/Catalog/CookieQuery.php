<?php
/**
 * Filtered, sorted, paged cookie list for the admin table (cookies joined with their service).
 * New cookies always come first, then the requested order.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

use DSolutions\CookieConsent\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class CookieQuery {

	/** Sortable column => SQL expression. */
	const SORTS = array(
		'name'      => 'c.name',
		'service'   => 's.name',
		'category'  => 'category_key',
		'last_seen' => 'c.last_seen_at',
		'seen'      => 'c.seen_count',
		'status'    => 'c.status',
		'source'    => 'c.source',
	);

	/** SQL for a cookie's category: its service's category, "unclassified" without a service. */
	const CATEGORY_SQL = "COALESCE(s.category_key, 'unclassified')";

	/**
	 * Run the query.
	 *
	 * @param array $args category, status, source, search, orderby, order, page, per_page.
	 * @return array{rows: array[], total: int}
	 */
	public static function run( array $args ) {
		global $wpdb;
		$cookies  = Schema::cookies_table();
		$services = Schema::services_table();
		$from     = "FROM {$cookies} c LEFT JOIN {$services} s ON s.id = c.service_id";
		$where    = self::where( $args );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) {$from}{$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- tables are trusted; $where is prepared.

		$orderby  = isset( $args['orderby'], self::SORTS[ $args['orderby'] ] ) ? self::SORTS[ $args['orderby'] ] : 'c.last_seen_at';
		$order    = isset( $args['order'] ) && 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 200, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$offset   = max( 0, ( ( isset( $args['page'] ) ? (int) $args['page'] : 1 ) - 1 ) * $per_page );

		$sql  = 'SELECT c.*, s.name AS service_name, s.slug AS service_slug, s.privacy_url AS service_privacy_url, ' . self::CATEGORY_SQL . " AS category_key {$from}{$where}";
		$sql .= " ORDER BY (c.status = 'new') DESC, {$orderby} {$order}, c.name ASC";
		$sql .= ' LIMIT ' . $per_page . ' OFFSET ' . $offset; // Integers.
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- $orderby and $order come from the whitelist above, $where is prepared, paging values are integers.

		return array(
			'rows'  => array_map( array( CookieRow::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Row counts per status (for the view links above the table).
	 *
	 * @return array<string,int>
	 */
	public static function status_counts() {
		global $wpdb;
		$table = Schema::cookies_table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$out   = array_fill_keys( CookieRepository::STATUSES, 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Prepared WHERE clause for the filters.
	 *
	 * @param array $args Filters.
	 * @return string SQL starting with " WHERE" or empty.
	 */
	private static function where( array $args ) {
		global $wpdb;
		$parts = array();
		if ( ! empty( $args['category'] ) && in_array( $args['category'], ServiceRepository::CATEGORIES, true ) ) {
			$parts[] = $wpdb->prepare( self::CATEGORY_SQL . ' = %s', $args['category'] ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constant expression.
		}
		if ( ! empty( $args['status'] ) && in_array( $args['status'], CookieRepository::STATUSES, true ) ) {
			$parts[] = $wpdb->prepare( 'c.status = %s', $args['status'] );
		}
		if ( ! empty( $args['source'] ) && in_array( $args['source'], CookieRepository::SOURCES, true ) ) {
			$parts[] = $wpdb->prepare( 'c.source = %s', $args['source'] );
		}
		if ( isset( $args['search'] ) && '' !== trim( (string) $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$parts[] = $wpdb->prepare( '(c.name LIKE %s OR s.name LIKE %s)', $like, $like );
		}
		return $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '';
	}

	/**
	 * Number of cookies per category, ignored ones left out (the overview screen).
	 *
	 * @return array<string,int> Category key => count (every category present).
	 */
	public static function category_counts() {
		global $wpdb;
		$cookies  = Schema::cookies_table();
		$services = Schema::services_table();
		$sql      = 'SELECT ' . self::CATEGORY_SQL . " AS category_key, COUNT(*) AS n FROM {$cookies} c LEFT JOIN {$services} s ON s.id = c.service_id WHERE c.status <> 'ignored' GROUP BY category_key";
		$rows     = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom tables, no user input.
		$out      = array_fill_keys( ServiceRepository::CATEGORIES, 0 );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ $row['category_key'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Cookies that are still unclassified and not ignored.
	 *
	 * @param int $limit Most rows to return.
	 * @return array{rows: array[], total: int}
	 */
	public static function unclassified_open( $limit = 20 ) {
		global $wpdb;
		$cookies  = Schema::cookies_table();
		$services = Schema::services_table();
		$where    = ' WHERE ' . self::CATEGORY_SQL . " = 'unclassified' AND c.status <> 'ignored'";
		$from     = " FROM {$cookies} c LEFT JOIN {$services} s ON s.id = c.service_id";
		$total    = (int) $wpdb->get_var( 'SELECT COUNT(*)' . $from . $where ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- custom tables, constant SQL.
		$rows     = $wpdb->get_results( $wpdb->prepare( 'SELECT c.*' . $from . $where . ' ORDER BY c.name ASC LIMIT %d', max( 1, (int) $limit ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, constant SQL.
		return array(
			'rows'  => array_map( array( CookieRow::class, 'hydrate' ), is_array( $rows ) ? $rows : array() ),
			'total' => $total,
		);
	}

	/**
	 * Confirmed and automatic cookies that a scan or a visitor actually reported (the "present on the site" set).
	 *
	 * @return array[] Cookie rows.
	 */
	public static function seen_on_site() {
		global $wpdb;
		$table = Schema::cookies_table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE status IN ('confirmed','auto') AND last_seen_at IS NOT NULL ORDER BY name ASC LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, constant SQL.
		return array_map( array( CookieRow::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}
}
