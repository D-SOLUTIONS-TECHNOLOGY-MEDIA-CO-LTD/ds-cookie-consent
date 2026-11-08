<?php
/**
 * Quick setup of unclassified cookies from the compliance report. One action per cookie (or one for a selection):
 *   necessary  - file under "Cần thiết" and confirm;
 *   analytics, marketing - file under the group, confirm (confirmed analytics / marketing cookies are blocked until
 *                consent, whatever the unclassified-cookie mode is);
 *   keep       - add the name to `blocking.keep_cookies` (never filtered or purged), file under "Cần thiết", confirm;
 *   ignore     - ignore.
 * Served by admin-ajax (the report removes the row without a reload) and by admin-post (plain form when JS is off).
 * Nonce `dscc_quick_setup` + `manage_options`.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Catalog\CookieActions;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Settings\FieldValidators;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class QuickSetup {

	const NONCE   = 'dscc_quick_setup';
	const ACTIONS = array( 'necessary', 'analytics', 'marketing', 'keep', 'ignore' );

	/** Entries the keep list may hold (the setting's own limit). */
	const MAX_KEEP = 30;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'wp_ajax_dscc_quick_setup', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_dscc_quick_setup', array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Values for the admin script.
	 *
	 * @return array
	 */
	public static function script_data() {
		return array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
		);
	}

	/**
	 * Apply an action to unclassified cookies.
	 *
	 * @param int[]  $ids    Cookie ids.
	 * @param string $action One of ACTIONS.
	 * @return array {done: int[] ids resolved, failed: int[] ids that could not be resolved}
	 */
	public static function apply( array $ids, $action ) {
		$out = array(
			'done'   => array(),
			'failed' => array(),
		);
		if ( ! in_array( $action, self::ACTIONS, true ) ) {
			$out['failed'] = array_map( 'intval', $ids );
			return $out;
		}
		foreach ( array_unique( array_filter( array_map( 'absint', $ids ) ) ) as $id ) {
			$cookie = CookieRepository::find( $id );
			if ( ! $cookie || 'unclassified' !== CookieActions::category_of( $cookie ) ) {
				$out['failed'][] = $id;
				continue;
			}
			if ( self::apply_one( $cookie, $action ) ) {
				$out['done'][] = $id;
			} else {
				$out['failed'][] = $id;
			}
		}
		if ( $out['done'] ) {
			FindingStore::clear_dropped();
			do_action( 'dscc_catalog_changed' ); // Rebuilds the frontend catalog and the blocking rules.
		}
		return $out;
	}

	/**
	 * Apply an action to one cookie row.
	 *
	 * @param array  $cookie Cookie row.
	 * @param string $action Action.
	 * @return bool
	 */
	private static function apply_one( array $cookie, $action ) {
		$id = (int) $cookie['id'];
		if ( 'ignore' === $action ) {
			return CookieActions::ignore( array( $id ) ) > 0;
		}
		$keep = 'keep' === $action;
		if ( $keep && ! self::can_keep( (string) $cookie['name'] ) ) {
			return false;
		}
		$category = in_array( $action, array( 'analytics', 'marketing' ), true ) ? $action : 'necessary';
		CookieActions::move_category( array( $id ), $category );
		$confirmed = CookieActions::confirm( array( $id ) );
		$ok        = $confirmed['changed'] > 0 || 0 === $confirmed['skipped'];
		if ( $ok && $keep && ! self::add_to_keep_list( (string) $cookie['name'] ) ) {
			// The setting could not be saved: put the row back exactly as it was.
			CookieRepository::update( $id, array( 'service_id' => $cookie['service_id'] ) );
			CookieRepository::set_status_many( array( $id ), $cookie['status'] );
			return false;
		}
		return $ok;
	}

	/**
	 * Can the name be added to the keep list (valid literal name, room left)?
	 *
	 * @param string $name Cookie name.
	 * @return bool
	 */
	private static function can_keep( $name ) {
		$keep = Settings::get( 'blocking', 'keep_cookies', array() );
		$keep = is_array( $keep ) ? $keep : array();
		return in_array( $name, $keep, true ) || ( FieldValidators::cookie_pattern( $name ) && false === strpos( $name, '*' ) && count( $keep ) < self::MAX_KEEP );
	}

	/**
	 * Append a name to `blocking.keep_cookies` unless it is there already.
	 *
	 * @param string $name Cookie name (a literal name, never a pattern).
	 * @return bool False when the name is not allowed on the list or the list is full.
	 */
	private static function add_to_keep_list( $name ) {
		$group = Settings::group( 'blocking' );
		$keep  = isset( $group['keep_cookies'] ) && is_array( $group['keep_cookies'] ) ? $group['keep_cookies'] : array();
		if ( in_array( $name, $keep, true ) ) {
			return true;
		}
		if ( ! FieldValidators::cookie_pattern( $name ) || false !== strpos( $name, '*' ) || count( $keep ) >= self::MAX_KEEP ) {
			return false;
		}
		$keep[]                = $name;
		$group['keep_cookies'] = $keep;
		Settings::save_group( 'blocking', $group );
		return true;
	}

	/**
	 * Request values: ids and action. A row button sends "row_action" = "<action>:<id>", the bulk form sends
	 * "qs_action" and "ids[]".
	 *
	 * @return array {ids: int[], action: string}
	 */
	private static function request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by the callers before this runs.
		$row = isset( $_POST['row_action'] ) && is_string( $_POST['row_action'] ) ? sanitize_text_field( wp_unslash( $_POST['row_action'] ) ) : '';
		if ( '' !== $row && false !== strpos( $row, ':' ) ) {
			list( $action, $id ) = explode( ':', $row, 2 );
			return array(
				'ids'    => array( absint( $id ) ),
				'action' => sanitize_key( $action ),
			);
		}
		$ids = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', wp_unslash( $_POST['ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() on every element.
		return array(
			'ids'    => $ids,
			'action' => isset( $_POST['qs_action'] ) && is_string( $_POST['qs_action'] ) ? sanitize_key( wp_unslash( $_POST['qs_action'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * AJAX: JSON {success, data: {done: int[], failed: int[]}}.
	 */
	public static function ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'ds-cookie-consent' ) ), 403 );
		}
		$req    = self::request();
		$result = self::apply( $req['ids'], $req['action'] );
		if ( ! $result['done'] ) {
			wp_send_json_error( array_merge( $result, array( 'message' => __( 'Could not apply this to the selected cookies.', 'ds-cookie-consent' ) ) ), 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Plain form fallback: apply and go back to the report.
	 */
	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ds-cookie-consent' ), 403 );
		}
		check_admin_referer( self::NONCE );
		$req = self::request();
		self::apply( $req['ids'], $req['action'] );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=dscc' ) );
		exit;
	}
}
