<?php
/**
 * Consent log screen: filters, table, pagination and CSV export.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Consent\ConsentLogRepository;
use DSolutions\CookieConsent\Consent\LogValidator;

defined( 'ABSPATH' ) || exit;

final class LogPage {

	const SLUG     = 'dscc-log';
	const PER_PAGE = 50;

	/**
	 * Human label of a choice value.
	 *
	 * @param string $choice Choice.
	 * @return string
	 */
	public static function choice_label( $choice ) {
		$map = array(
			'accept_all' => __( 'Accept all', 'ds-cookie-consent' ),
			'reject_all' => __( 'Reject all', 'ds-cookie-consent' ),
			'custom'     => __( 'Custom', 'ds-cookie-consent' ),
			'withdraw'   => __( 'Withdraw consent', 'ds-cookie-consent' ),
		);
		return isset( $map[ $choice ] ) ? $map[ $choice ] : $choice;
	}

	/**
	 * Print the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'dscc_view_log' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the log.', 'ds-cookie-consent' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters in a GET form.
		$filters = array(
			'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
			'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			'choice'    => isset( $_GET['choice'] ) ? sanitize_key( wp_unslash( $_GET['choice'] ) ) : '',
		);
		$page    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result  = ConsentLogRepository::query( $filters, $page, self::PER_PAGE );
		$pages   = (int) max( 1, ceil( $result['total'] / self::PER_PAGE ) );
		$export  = wp_nonce_url(
			add_query_arg(
				array_merge( array( 'action' => 'dscc_export_log' ), array_filter( $filters ) ),
				admin_url( 'admin-post.php' )
			),
			'dscc_export_log'
		);
		$choices = LogValidator::CHOICES;
		include DSCC_DIR . 'templates/admin/log.php';
	}
}
