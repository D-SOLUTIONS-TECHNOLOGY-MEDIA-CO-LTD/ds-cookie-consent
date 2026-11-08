<?php
/**
 * Settings tab "Import / Export": download the configuration as JSON, or load a file / a bundled preset. An import is
 * always shown as a preview (counts of what would change) and applied only after a second explicit click; the
 * previewed document is kept server-side for 30 minutes, so what is applied is exactly what was shown.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Transfer\ExportBuilder;
use DSolutions\CookieConsent\Transfer\ImportPlanner;
use DSolutions\CookieConsent\Transfer\ImportReader;
use DSolutions\CookieConsent\Transfer\Presets;

defined( 'ABSPATH' ) || exit;

final class TransferTab {

	const TAB     = 'transfer';
	const TTL     = 1800;
	const MAX_KEY = 24;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_filter( 'dscc_admin_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'admin_post_dscc_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_dscc_import_preview', array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_post_dscc_import_apply', array( __CLASS__, 'handle_apply' ) );
	}

	/**
	 * Add the tab (just before "Advanced").
	 *
	 * @param array $tabs Registered tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		return SettingsPage::insert_tab(
			$tabs,
			self::TAB,
			array(
				'label'  => __( 'Import / Export', 'ds-cookie-consent' ),
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
		$presets = Presets::all();
		$pending = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token only selects a preview that belongs to the current user.
		$token = isset( $_GET['dscc_import'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_GET['dscc_import'] ) ) ) : '';
		if ( '' !== $token ) {
			$pending = get_transient( self::key( $token ) );
			$pending = is_array( $pending ) && isset( $pending['doc'], $pending['plan'] ) ? $pending : null;
		}
		include DSCC_DIR . 'templates/admin/tab-transfer.php';
	}

	/**
	 * Transient name of a stored preview.
	 *
	 * @param string $token Random token.
	 * @return string
	 */
	private static function key( $token ) {
		return 'dscc_import_' . get_current_user_id() . '_' . substr( $token, 0, self::MAX_KEY );
	}

	/**
	 * Capability and nonce guard.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ds-cookie-consent' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect to the tab.
	 *
	 * @param array $args Extra query arguments.
	 */
	private static function back( array $args = array() ) {
		wp_safe_redirect( SettingsPage::url( self::TAB, $args ) );
		exit;
	}

	/**
	 * Download the configuration.
	 */
	public static function handle_export() {
		self::guard( 'dscc_export' );
		$host = preg_replace( '/[^a-z0-9.-]/', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ds-cookie-consent-' . ( '' === $host ? 'site' : $host ) . '-' . gmdate( 'Ymd' ) . '.json"' );
		echo ExportBuilder::to_json(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		exit;
	}

	/**
	 * Read the uploaded file or the chosen preset and show what the import would change.
	 */
	public static function handle_preview() {
		self::guard( 'dscc_import_preview' );
		$json = self::posted_json();
		if ( is_wp_error( $json ) ) {
			Flash::add( 'error', $json->get_error_message() );
			self::back();
		}
		$doc = ImportReader::read( $json );
		if ( is_wp_error( $doc ) ) {
			Flash::add( 'error', $doc->get_error_message() );
			self::back();
		}
		$token = wp_generate_password( self::MAX_KEY, false, false );
		set_transient(
			self::key( $token ),
			array(
				'doc'  => $doc,
				'plan' => ImportPlanner::plan( $doc ),
			),
			self::TTL
		);
		self::back( array( 'dscc_import' => $token ) );
	}

	/**
	 * Apply the previewed import.
	 */
	public static function handle_apply() {
		self::guard( 'dscc_import_apply' );
		$token   = isset( $_POST['dscc_import_token'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['dscc_import_token'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard().
		$pending = '' === $token ? false : get_transient( self::key( $token ) );
		if ( ! is_array( $pending ) || ! isset( $pending['doc'] ) ) {
			Flash::add( 'error', __( 'The import preview has expired. Choose the file again.', 'ds-cookie-consent' ) );
			self::back();
		}
		delete_transient( self::key( $token ) );
		$plan = ImportPlanner::apply( $pending['doc'] );
		Flash::add(
			'success',
			sprintf(
				/* translators: 1: settings changed, 2: platforms added or updated, 3: cookies added or updated */
				__( 'Import finished: %1$d settings, %2$d platforms and %3$d cookies were added or updated.', 'ds-cookie-consent' ),
				$plan['settings']['count'] + array_sum( $plan['texts'] ),
				$plan['services']['new'] + $plan['services']['updated'],
				$plan['cookies']['new'] + $plan['cookies']['updated']
			)
		);
		self::back();
	}

	/**
	 * JSON text from the upload (wins) or from the selected bundled preset.
	 *
	 * @return string|\WP_Error
	 */
	private static function posted_json() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); the file is validated below and its content by ImportReader.
		$file = isset( $_FILES['dscc_import_file'] ) && is_array( $_FILES['dscc_import_file'] ) ? $_FILES['dscc_import_file'] : null;
		if ( $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			if ( UPLOAD_ERR_OK !== (int) $file['error'] || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
				return new \WP_Error( 'dscc_upload_failed', __( 'The file could not be uploaded.', 'ds-cookie-consent' ) );
			}
			if ( (int) $file['size'] > ImportReader::MAX_BYTES ) {
				return new \WP_Error( 'dscc_import_too_large', __( 'The file is larger than 2 MB.', 'ds-cookie-consent' ) );
			}
			$content = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the upload.
			return false === $content ? new \WP_Error( 'dscc_upload_failed', __( 'The file could not be uploaded.', 'ds-cookie-consent' ) ) : $content;
		}
		$preset = isset( $_POST['dscc_import_preset'] ) ? sanitize_key( wp_unslash( $_POST['dscc_import_preset'] ) ) : '';
		// phpcs:enable
		if ( '' === $preset ) {
			return new \WP_Error( 'dscc_import_nothing', __( 'Choose a file or a preset first.', 'ds-cookie-consent' ) );
		}
		$json = Presets::json( $preset );
		return null === $json ? new \WP_Error( 'dscc_import_no_preset', __( 'That preset does not exist.', 'ds-cookie-consent' ) ) : $json;
	}
}
