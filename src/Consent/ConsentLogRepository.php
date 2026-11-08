<?php
/**
 * Data access for the consent log table.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

use DSolutions\CookieConsent\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ConsentLogRepository {

	/**
	 * Insert one log row. Returns the new id or false.
	 *
	 * @param array $row Normalised row (see LogValidator) plus ip_masked and user_agent.
	 * @return int|false
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$ok = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
			Schema::log_table(),
			array(
				'consent_id'     => $row['consent_id'],
				'choice'         => $row['choice'],
				'analytics'      => (int) $row['analytics'],
				'marketing'      => (int) $row['marketing'],
				'services_json'  => isset( $row['services_json'] ) ? $row['services_json'] : null,
				'policy_version' => $row['policy_version'],
				'lang'           => $row['lang'],
				'page_url'       => $row['page_url'],
				'ip_masked'      => isset( $row['ip_masked'] ) ? $row['ip_masked'] : '',
				'user_agent'     => isset( $row['user_agent'] ) ? $row['user_agent'] : '',
				'src'            => $row['src'],
				'created_at'     => isset( $row['created_at'] ) ? $row['created_at'] : current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Build the WHERE clause (prepared) for the supported filters.
	 *
	 * @param array $filters date_from (Y-m-d), date_to (Y-m-d), choice.
	 * @return string SQL starting with " WHERE ..." or empty.
	 */
	private static function where( array $filters ) {
		global $wpdb;
		$parts = array();
		if ( ! empty( $filters['date_from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'] ) ) {
			$parts[] = $wpdb->prepare( 'created_at >= %s', $filters['date_from'] . ' 00:00:00' );
		}
		if ( ! empty( $filters['date_to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'] ) ) {
			$parts[] = $wpdb->prepare( 'created_at <= %s', $filters['date_to'] . ' 23:59:59' );
		}
		if ( ! empty( $filters['choice'] ) && in_array( $filters['choice'], LogValidator::CHOICES, true ) ) {
			$parts[] = $wpdb->prepare( 'choice = %s', $filters['choice'] );
		}
		return $parts ? ' WHERE ' . implode( ' AND ', $parts ) : '';
	}

	/**
	 * Paged list, newest first.
	 *
	 * @param array $filters  Filters.
	 * @param int   $page     1-based page.
	 * @param int   $per_page Rows per page.
	 * @return array {rows: array, total: int}
	 */
	public static function query( array $filters, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table  = Schema::log_table();
		$where  = self::where( $filters );
		$offset = max( 0, ( (int) $page - 1 ) * (int) $per_page );

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and where are built from trusted pieces.
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", (int) $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			ARRAY_A
		);
		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Row counts per choice for the last N days.
	 *
	 * @param int $days Window in days.
	 * @return array<string,int>
	 */
	public static function counts_by_choice( $days ) {
		global $wpdb;
		$table = Schema::log_table();
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) $days * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT choice, COUNT(*) AS n FROM {$table} WHERE created_at >= %s GROUP BY choice", $since ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out   = array_fill_keys( LogValidator::CHOICES, 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['choice'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Delete rows older than N months (0 = keep everything). Returns deleted row count.
	 *
	 * @param int $months Retention.
	 * @return int
	 */
	public static function delete_older_than( $months ) {
		global $wpdb;
		$months = (int) $months;
		if ( $months <= 0 ) {
			return 0;
		}
		$table  = Schema::log_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) );
		$n      = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $n;
	}

	/**
	 * Stream rows in id order in batches (export).
	 *
	 * @param array    $filters Filters.
	 * @param callable $each    Called with each row (array).
	 * @param int      $batch   Batch size.
	 */
	public static function each_row( array $filters, $each, $batch = 2000 ) {
		global $wpdb;
		$table   = Schema::log_table();
		$where   = self::where( $filters );
		$last_id = 0;
		$batch   = (int) $batch;
		do {
			// $table comes from the trusted prefix, $where from self::where() (already prepared), $last_id is an int.
			$sql  = "SELECT * FROM {$table}" . ( '' !== $where ? $where : ' WHERE 1=1' ) . ' AND id > ' . (int) $last_id . ' ORDER BY id ASC LIMIT %d';
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $batch ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = is_array( $rows ) ? $rows : array();
			$seen = count( $rows );
			foreach ( $rows as $row ) {
				call_user_func( $each, $row );
				$last_id = (int) $row['id'];
			}
		} while ( $seen === $batch );
	}
}
