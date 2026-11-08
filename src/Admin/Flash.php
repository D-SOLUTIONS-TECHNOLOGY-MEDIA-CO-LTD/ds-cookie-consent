<?php
/**
 * One-request admin notices carried across a redirect (stored briefly per user).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

defined( 'ABSPATH' ) || exit;

final class Flash {

	/**
	 * Queue a notice for the next settings screen.
	 *
	 * @param string $type    success|error|warning.
	 * @param string $message Plain text.
	 */
	public static function add( $type, $message ) {
		$type    = in_array( $type, array( 'success', 'error', 'warning' ), true ) ? $type : 'success';
		$items   = get_transient( self::key() );
		$items   = is_array( $items ) ? $items : array();
		$items[] = array(
			'type'    => $type,
			'message' => (string) $message,
		);
		set_transient( self::key(), array_slice( $items, -5 ), 120 );
	}

	/**
	 * Read and clear the queued notices.
	 *
	 * @return array[] Items with type and message.
	 */
	public static function take() {
		$items = get_transient( self::key() );
		if ( false === $items ) {
			return array();
		}
		delete_transient( self::key() );
		return is_array( $items ) ? $items : array();
	}

	/**
	 * Transient name for the current user.
	 *
	 * @return string
	 */
	private static function key() {
		return 'dscc_flash_' . get_current_user_id();
	}
}
