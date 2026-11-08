<?php
/**
 * Data access for the scan runs table. `summary` and `report` are stored as JSON and returned decoded.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ScanRunRepository {

	const TYPES = array( 'server', 'deep', 'beacon' );

	/** Runs kept per type; older ones are deleted when a new run starts. */
	const KEEP = 20;

	/**
	 * Start a run.
	 *
	 * @param string $type    server|deep|beacon.
	 * @param array  $summary Summary data.
	 * @param array  $report  Report data.
	 * @param string $status  Initial status.
	 * @return int Run id, 0 on failure.
	 */
	public static function create( $type, array $summary, array $report, $status = 'running' ) {
		global $wpdb;
		$type = in_array( $type, self::TYPES, true ) ? $type : 'server';
		$ok   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			Schema::scan_runs_table(),
			array(
				'type'       => $type,
				'started_at' => current_time( 'mysql', true ),
				'status'     => $status,
				'summary'    => (string) wp_json_encode( $summary ),
				'report'     => (string) wp_json_encode( $report ),
			)
		);
		if ( ! $ok ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		self::prune( $type );
		return $id;
	}

	/**
	 * One run, decoded.
	 *
	 * @param int $id Run id.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = Schema::scan_runs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Newest run of a type with a given status.
	 *
	 * @param string $type   Run type.
	 * @param string $status Status ('' = any).
	 * @return array|null
	 */
	public static function latest( $type = 'server', $status = 'done' ) {
		global $wpdb;
		$table = Schema::scan_runs_table();
		$sql   = $wpdb->prepare( "SELECT * FROM {$table} WHERE type = %s", $type ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		if ( '' !== $status ) {
			$sql .= $wpdb->prepare( ' AND status = %s', $status );
		}
		$row = $wpdb->get_row( $sql . ' ORDER BY id DESC LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- both parts are prepared above.
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * When a run finished (MySQL GMT). A run whose finish time was never written (a failed update) falls back to the
	 * time the scan itself reported, then to the start time.
	 *
	 * @param array $run Run from find() / latest().
	 * @return string
	 */
	public static function finished_gmt( array $run ) {
		if ( ! empty( $run['finished_at'] ) && '0000-00-00 00:00:00' !== $run['finished_at'] ) {
			return (string) $run['finished_at'];
		}
		if ( ! empty( $run['summary']['scanned_at'] ) ) {
			return gmdate( 'Y-m-d H:i:s', (int) $run['summary']['scanned_at'] );
		}
		return (string) $run['started_at'];
	}

	/**
	 * Change status, finish time, summary and/or report of a run.
	 *
	 * @param int   $id     Run id.
	 * @param array $fields Any of status, finished_at (mysql GMT), summary (array), report (array).
	 * @return bool
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$data = array();
		if ( isset( $fields['status'] ) ) {
			$data['status'] = (string) $fields['status'];
		}
		if ( isset( $fields['finished_at'] ) ) {
			$data['finished_at'] = (string) $fields['finished_at'];
		}
		foreach ( array( 'summary', 'report' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$data[ $key ] = (string) wp_json_encode( $fields[ $key ] );
			}
		}
		if ( ! $data ) {
			return false;
		}
		return false !== $wpdb->update( Schema::scan_runs_table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
	}

	/**
	 * Keep only the newest runs of a type.
	 *
	 * @param string $type Run type.
	 */
	private static function prune( $type ) {
		global $wpdb;
		$table = Schema::scan_runs_table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE type = %s ORDER BY id DESC LIMIT 1000 OFFSET %d", $type, self::KEEP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		if ( $ids ) {
			$wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integers only.
		}
	}

	/**
	 * Decode the JSON columns.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	private static function hydrate( array $row ) {
		$row['id']      = (int) $row['id'];
		$summary        = json_decode( (string) $row['summary'], true );
		$report         = json_decode( (string) $row['report'], true );
		$row['summary'] = is_array( $summary ) ? $summary : array();
		$row['report']  = is_array( $report ) ? $report : array();
		return $row;
	}
}
