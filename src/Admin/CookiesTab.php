<?php
/**
 * Settings tab "Cookie": the cookie catalog table with filters, bulk actions, inline quick edit and a form to add
 * a cookie by hand. The menu badge shows how many cookies are waiting for review (status new).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Catalog\CookieActions;
use DSolutions\CookieConsent\Catalog\CookieQuery;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\ServiceRepository;

defined( 'ABSPATH' ) || exit;

final class CookiesTab {

	const TAB = 'cookies';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_filter( 'dscc_admin_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_bulk' ) );
		add_action( 'admin_post_dscc_cookie_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'wp_ajax_dscc_cookie_quick_edit', array( __CLASS__, 'ajax_quick_edit' ) );
	}

	/**
	 * Add the tab to the settings screen.
	 *
	 * @param array $tabs Registered tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		$count = CookieRepository::count_new();
		return SettingsPage::insert_tab(
			$tabs,
			self::TAB,
			array(
				/* translators: %d: number of new cookies */
				'label'  => $count > 0 ? sprintf( __( 'Cookies (%d new)', 'ds-cookie-consent' ), $count ) : __( 'Cookies', 'ds-cookie-consent' ),
				'render' => array( __CLASS__, 'render' ),
				'save'   => null,
				'form'   => false,
			)
		);
	}

	/**
	 * Print the tab.
	 */
	public static function render() {
		$table = new CookieListTable();
		$table->prepare_items();
		$filters  = CookieListTable::request_filters();
		$counts   = CookieQuery::status_counts();
		$services = array();
		foreach ( ServiceRepository::all() as $service ) {
			$services[ $service['slug'] ] = $service['name'];
		}
		$base       = SettingsPage::url( self::TAB );
		$scan       = ScanPanel::data();
		$compliance = ComplianceReport::build();
		include DSCC_DIR . 'templates/admin/tab-cookies.php';
	}

	/**
	 * Handle the bulk-action form (POSTed to the tab URL; WP_List_Table names its selects "action"/"action2",
	 * which is why this is not an admin-post handler).
	 */
	public static function maybe_handle_bulk() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen detection only; the nonce is verified below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'post' !== $method || SettingsPage::SLUG !== $page || self::TAB !== $tab ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ds-cookie-consent' ), 403 );
		}
		check_admin_referer( 'bulk-cookies' );
		$table  = new CookieListTable();
		$action = $table->current_action();
		$ids    = isset( $_POST['cookie_ids'] ) && is_array( $_POST['cookie_ids'] ) ? array_map( 'absint', wp_unslash( $_POST['cookie_ids'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() on every element.
		if ( $action && $ids ) {
			self::run_bulk( (string) $action, $ids );
		}
		wp_safe_redirect( SettingsPage::url( self::TAB ) );
		exit;
	}

	/**
	 * Execute one bulk action and queue the notice.
	 *
	 * @param string $action Action key.
	 * @param int[]  $ids    Cookie ids.
	 */
	private static function run_bulk( $action, array $ids ) {
		if ( 'confirm' === $action ) {
			$result = CookieActions::confirm( $ids );
			/* translators: %d: number of cookies */
			Flash::add( 'success', sprintf( __( 'Cookies confirmed: %d.', 'ds-cookie-consent' ), $result['changed'] ) );
			if ( $result['skipped'] > 0 ) {
				/* translators: %d: number of cookies */
				Flash::add( 'warning', sprintf( __( 'Skipped cookies without a vendor or category: %d. Move them to a category first, then confirm.', 'ds-cookie-consent' ), $result['skipped'] ) );
			}
		} elseif ( 'ignore' === $action ) {
			/* translators: %d: number of cookies */
			Flash::add( 'success', sprintf( __( 'Cookies ignored: %d.', 'ds-cookie-consent' ), CookieActions::ignore( $ids ) ) );
		} elseif ( 'delete' === $action ) {
			/* translators: %d: number of cookies */
			Flash::add( 'success', sprintf( __( 'Cookies deleted: %d.', 'ds-cookie-consent' ), CookieActions::delete( $ids ) ) );
		} elseif ( 0 === strpos( $action, 'move_' ) ) {
			/* translators: %d: number of cookies */
			Flash::add( 'success', sprintf( __( 'Cookies moved to another category: %d.', 'ds-cookie-consent' ), CookieActions::move_category( $ids, substr( $action, 5 ) ) ) );
		}
	}

	/**
	 * Add a cookie by hand.
	 */
	public static function handle_add() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ds-cookie-consent' ), 403 );
		}
		check_admin_referer( 'dscc_cookie_add' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; CookieActions::add_manual() sanitises every field.
		$input  = isset( $_POST['dscc_cookie'] ) && is_array( $_POST['dscc_cookie'] ) ? wp_unslash( $_POST['dscc_cookie'] ) : array();
		$result = CookieActions::add_manual( $input );
		if ( is_wp_error( $result ) ) {
			Flash::add( 'error', $result->get_error_message() );
		} else {
			Flash::add( 'success', __( 'Cookie added.', 'ds-cookie-consent' ) );
		}
		wp_safe_redirect( SettingsPage::url( self::TAB ) );
		exit;
	}

	/**
	 * Quick edit (AJAX): saves purpose/duration texts and category, answers with the values to show in the row.
	 */
	public static function ajax_quick_edit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'ds-cookie-consent' ) ), 403 );
		}
		check_ajax_referer( 'dscc_cookie_quick_edit', 'nonce' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; CookieActions::quick_edit() sanitises the fields.
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$fields = array();
		foreach ( array( 'category', 'purpose', 'purpose_en', 'duration_label', 'duration_label_en', 'table_group', 'applies_to', 'applies_to_en' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$fields[ $key ] = wp_unslash( $_POST[ $key ] );
			}
		}
		// phpcs:enable
		$row = CookieActions::quick_edit( $id, $fields );
		if ( is_wp_error( $row ) ) {
			wp_send_json_error( array( 'message' => $row->get_error_message() ), 400 );
		}
		$labels = CatalogLabels::categories_with_unclassified();
		wp_send_json_success(
			array(
				'id'                => $row['id'],
				'category'          => 'unclassified' === $row['category_key'] ? '' : $row['category_key'],
				'category_label'    => isset( $labels[ $row['category_key'] ] ) ? $labels[ $row['category_key'] ] : $row['category_key'],
				'purpose'           => (string) $row['purpose'],
				'purpose_en'        => (string) $row['purpose_en'],
				'duration_label'    => $row['duration_label'],
				'duration_label_en' => $row['duration_label_en'],
				'table_group'       => $row['table_group'],
				'applies_to'        => $row['applies_to'],
				'applies_to_en'     => $row['applies_to_en'],
			)
		);
	}
}
