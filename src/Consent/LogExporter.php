<?php
/**
 * CSV export of the consent log (UTF-8 with BOM so Excel reads Vietnamese correctly).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

defined( 'ABSPATH' ) || exit;

final class LogExporter {

	const COLUMNS = array( 'created_at_utc', 'choice', 'analytics', 'marketing', 'services', 'consent_id', 'policy_version', 'lang', 'page_url', 'ip_masked', 'user_agent', 'src' );

	/**
	 * Write the CSV (BOM, header, rows) to an open stream.
	 *
	 * @param resource $out     Writable stream.
	 * @param array    $filters Log filters.
	 * @return int Number of data rows written.
	 */
	public static function write( $out, array $filters = array() ) {
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- writing to a php://output stream.
		fputcsv( $out, self::COLUMNS, ',', '"', '' );
		$count = 0;
		ConsentLogRepository::each_row(
			$filters,
			static function ( $r ) use ( $out, &$count ) {
				fputcsv(
					$out,
					array_map(
						array( __CLASS__, 'safe_cell' ),
						array( $r['created_at'], $r['choice'], $r['analytics'], $r['marketing'], (string) $r['services_json'], $r['consent_id'], $r['policy_version'], $r['lang'], $r['page_url'], $r['ip_masked'], $r['user_agent'], $r['src'] )
					),
					',',
					'"',
					''
				);
				++$count;
			}
		);
		return $count;
	}

	/**
	 * Neutralise spreadsheet formula injection: the user agent is attacker controlled.
	 *
	 * @param mixed $value Cell value.
	 * @return string
	 */
	public static function safe_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Stream the file to the browser (admin-post handler).
	 */
	public static function handle_download() {
		if ( ! current_user_can( 'dscc_view_log' ) ) {
			wp_die( esc_html__( 'You do not have permission to export the consent log.', 'ds-cookie-consent' ), 403 );
		}
		check_admin_referer( 'dscc_export_log' );

		$filters = array(
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'choice'    => isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '',
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=dscc-consent-log-' . gmdate( 'Y-m-d' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming download.
		self::write( $out, $filters );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
