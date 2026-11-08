<?php
/**
 * Settings screen: tab registry (filter dscc_admin_tabs), save handlers and admin assets.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Frontend\Assets;
use DSolutions\CookieConsent\Frontend\Preview;
use DSolutions\CookieConsent\Settings\ContrastChecker;
use DSolutions\CookieConsent\Settings\DefaultTexts;
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	const SLUG = 'dscc-settings';

	/** Bundled admin script, relative to the plugin directory. */
	const ADMIN_JS = 'assets/dist/dscc-admin.js';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'admin_post_dscc_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_dscc_restore_texts', array( __CLASS__, 'handle_restore_texts' ) );
		add_action( 'admin_post_dscc_bump_version', array( __CLASS__, 'handle_bump_version' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( DSCC_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Registered tabs: slug => [label, render, save]. Later phases add tabs through the filter.
	 *
	 * @return array<string,array>
	 */
	public static function tabs() {
		$tabs = array(
			'appearance' => array(
				'label'  => __( 'Appearance', 'ds-cookie-consent' ),
				'render' => array( __CLASS__, 'render_appearance' ),
				'save'   => null,
			),
			'content'    => array(
				'label'  => __( 'Content', 'ds-cookie-consent' ),
				'render' => array( __CLASS__, 'render_content' ),
				'save'   => array( __CLASS__, 'save_content' ),
			),
			'advanced'   => array(
				'label'  => __( 'Advanced', 'ds-cookie-consent' ),
				'render' => array( __CLASS__, 'render_advanced' ),
				'save'   => null,
			),
		);
		return (array) apply_filters( 'dscc_admin_tabs', $tabs );
	}

	/**
	 * Insert a tab just before another one (default: the Advanced tab), so feature tabs read in the order
	 * Giao diện, Nội dung, ..., Nâng cao. Appends when the anchor tab is missing.
	 *
	 * @param array  $tabs   Registered tabs.
	 * @param string $key    New tab slug.
	 * @param array  $tab    New tab definition.
	 * @param string $before Slug of the tab to insert before.
	 * @return array
	 */
	public static function insert_tab( array $tabs, $key, array $tab, $before = 'advanced' ) {
		if ( ! isset( $tabs[ $before ] ) ) {
			$tabs[ $key ] = $tab;
			return $tabs;
		}
		$out = array();
		foreach ( $tabs as $slug => $def ) {
			if ( $slug === $before ) {
				$out[ $key ] = $tab;
			}
			$out[ $slug ] = $def;
		}
		return $out;
	}

	/**
	 * URL of a settings tab.
	 *
	 * @param string $tab Tab slug.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $tab = 'appearance', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * "Settings" link on the plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ds-cookie-consent' ) . '</a>' );
		return $links;
	}

	/**
	 * Print the settings screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'ds-cookie-consent' ), 403 );
		}
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'appearance';
		if ( ! isset( $tabs[ $current ] ) ) {
			$current = 'appearance';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag.
		$message = isset( $_GET['dscc_msg'] ) ? sanitize_key( wp_unslash( $_GET['dscc_msg'] ) ) : '';
		include DSCC_DIR . 'templates/admin/settings.php';
	}

	/**
	 * Contrast warnings for the saved appearance settings.
	 *
	 * @return string[] Human readable warnings.
	 */
	public static function contrast_warnings() {
		$messages = array(
			/* translators: %s: contrast ratio, for example 3.2 */
			'primary_on_white' => __( 'White text on the primary colour (Accept button) only reaches a contrast of %s:1, below the 4.5:1 minimum (WCAG AA).', 'ds-cookie-consent' ),
			/* translators: %s: contrast ratio, for example 3.2 */
			'text_on_bar'      => __( 'Banner text on the banner background only reaches a contrast of %s:1, below the 4.5:1 minimum (WCAG AA).', 'ds-cookie-consent' ),
		);
		$out      = array();
		foreach ( ContrastChecker::failures( Settings::group( 'appearance' ) ) as $code => $ratio ) {
			$out[] = sprintf( $messages[ $code ], $ratio );
		}
		return $out;
	}

	/* ---------------------------------------------------------------- Tab renderers */

	/**
	 * Appearance tab.
	 */
	public static function render_appearance() {
		$s = Settings::group( 'appearance' );
		include DSCC_DIR . 'templates/admin/tab-appearance.php';
	}

	/**
	 * Content tab.
	 */
	public static function render_content() {
		$s = Settings::group( 'content' );
		include DSCC_DIR . 'templates/admin/tab-content.php';
	}

	/**
	 * Advanced tab.
	 */
	public static function render_advanced() {
		$s = Settings::group( 'advanced' );
		include DSCC_DIR . 'templates/admin/tab-advanced.php';
	}

	/* ---------------------------------------------------------------- Handlers */

	/**
	 * Capability guard shared by the admin-post handlers (each handler verifies its own nonce).
	 */
	private static function require_capability() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ds-cookie-consent' ), 403 );
		}
	}

	/**
	 * Save one tab.
	 */
	public static function handle_save() {
		self::require_capability();
		check_admin_referer( 'dscc_save_settings' );
		$tabs = self::tabs();
		$tab  = isset( $_POST['dscc_tab'] ) ? sanitize_key( wp_unslash( $_POST['dscc_tab'] ) ) : '';
		if ( ! isset( $tabs[ $tab ] ) ) {
			wp_die( esc_html__( 'Invalid tab.', 'ds-cookie-consent' ), 400 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every value is sanitised by SettingsSchema::sanitize_group().
		$input = isset( $_POST['dscc'] ) && is_array( $_POST['dscc'] ) ? wp_unslash( $_POST['dscc'] ) : array();

		SettingsSchema::take_rejections(); // Forget rejections left over from earlier sanitising in this request.
		if ( isset( $tabs[ $tab ]['save'] ) && is_callable( $tabs[ $tab ]['save'] ) ) {
			call_user_func( $tabs[ $tab ]['save'], $input );
		} elseif ( isset( SettingsSchema::fields()[ $tab ] ) ) {
			Settings::save_group( $tab, isset( $input[ $tab ] ) && is_array( $input[ $tab ] ) ? $input[ $tab ] : array() );
		}
		// A refused custom stylesheet keeps its previous value; tell the admin why instead of saying "saved".
		$rejected = SettingsSchema::take_rejections();
		$message  = 'saved';
		if ( isset( $rejected['custom_css'] ) ) {
			$message = 'css_' . $rejected['custom_css'];
		} elseif ( isset( $rejected['consent_regions'] ) ) {
			$message = 'regions_' . $rejected['consent_regions'];
		} elseif ( isset( $rejected['cookie_name'] ) ) {
			$message = 'cookie_name_kept';
		} elseif ( isset( $rejected['known_db_url'] ) ) {
			$message = 'known_db_url_not_https';
		}
		wp_safe_redirect( self::url( $tab, array( 'dscc_msg' => $message ) ) );
		exit;
	}

	/**
	 * Content tab: policy settings group plus the text overrides for both languages.
	 *
	 * @param array $input Unslashed dscc[] input.
	 */
	public static function save_content( array $input ) {
		Settings::save_group( 'content', isset( $input['content'] ) && is_array( $input['content'] ) ? $input['content'] : array() );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in handle_save(); values sanitised by SettingsSchema::sanitize_texts().
		$texts = isset( $_POST['dscc_texts'] ) && is_array( $_POST['dscc_texts'] ) ? wp_unslash( $_POST['dscc_texts'] ) : array();
		foreach ( DefaultTexts::LANGS as $lang ) {
			if ( isset( $texts[ $lang ] ) && is_array( $texts[ $lang ] ) ) {
				update_option( 'dscc_texts_' . $lang, SettingsSchema::sanitize_texts( $lang, $texts[ $lang ] ), true );
			}
		}
	}

	/**
	 * Restore default texts for one or both languages.
	 */
	public static function handle_restore_texts() {
		self::require_capability();
		check_admin_referer( 'dscc_restore_texts', 'dscc_restore_nonce' );
		$lang = isset( $_REQUEST['lang'] ) ? sanitize_key( wp_unslash( $_REQUEST['lang'] ) ) : 'all';
		foreach ( DefaultTexts::LANGS as $l ) {
			if ( 'all' === $lang || $lang === $l ) {
				delete_option( 'dscc_texts_' . $l );
			}
		}
		wp_safe_redirect( self::url( 'content', array( 'dscc_msg' => 'texts_restored' ) ) );
		exit;
	}

	/**
	 * Ask every visitor to choose again.
	 */
	public static function handle_bump_version() {
		self::require_capability();
		check_admin_referer( 'dscc_bump_version', 'dscc_bump_nonce' );
		Settings::bump_policy_version();
		wp_safe_redirect( self::url( 'advanced', array( 'dscc_msg' => 'version_bumped' ) ) );
		exit;
	}

	/* ---------------------------------------------------------------- Assets */

	/**
	 * Enqueue admin CSS/JS on the plugin screens only.
	 */
	public static function enqueue_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, 'dscc' ) ) {
			return;
		}
		wp_enqueue_style( 'dscc-admin', DSCC_URL . 'assets/dist/dscc-admin.css', array(), Assets::version( 'assets/dist/dscc-admin.css' ) );
		if ( 'dscc' === $page ) {
			// Overview screen: only the compliance report's quick setup needs a script.
			wp_enqueue_script( 'dscc-admin', DSCC_URL . self::ADMIN_JS, array( 'jquery' ), Assets::version( self::ADMIN_JS ), true );
			wp_localize_script(
				'dscc-admin',
				'dsccAdmin',
				array(
					'quick' => QuickSetup::script_data(),
					'i18n'  => array( 'qsError' => __( 'Could not apply the change. Please try again.', 'ds-cookie-consent' ) ),
				)
			);
			return;
		}
		if ( self::SLUG !== $page ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'dscc-admin', DSCC_URL . 'assets/dist/dscc-admin.js', array( 'jquery', 'wp-color-picker' ), Assets::version( 'assets/dist/dscc-admin.js' ), true );
		wp_localize_script(
			'dscc-admin',
			'dsccAdmin',
			array(
				'previewBase' => Preview::base_url(),
				'siteName'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'scan'        => ScanPanel::script_data(),
				'quick'       => QuickSetup::script_data(),
				'i18n'        => array(
					/* translators: %s: contrast ratio */
					'contrastPrimary' => __( 'White text on the primary colour reaches a contrast of %s:1 (4.5:1 minimum required).', 'ds-cookie-consent' ),
					/* translators: %s: contrast ratio */
					'contrastText'    => __( 'Banner text on the banner background reaches a contrast of %s:1 (4.5:1 minimum required).', 'ds-cookie-consent' ),
					'pickLogo'        => __( 'Select logo', 'ds-cookie-consent' ),
					'useImage'        => __( 'Use this image', 'ds-cookie-consent' ),
					'qeCategory'      => __( 'Category', 'ds-cookie-consent' ),
					/* translators: %s: vendor name */
					'qeVendorNote'    => __( 'Changing the category applies to the whole vendor "%s" (every cookie of this vendor).', 'ds-cookie-consent' ),
					'qePurposeVi'     => __( 'Purpose (Vietnamese)', 'ds-cookie-consent' ),
					'qePurposeEn'     => __( 'Purpose (English)', 'ds-cookie-consent' ),
					'qeDurationVi'    => __( 'Duration (Vietnamese)', 'ds-cookie-consent' ),
					'qeDurationEn'    => __( 'Duration (English)', 'ds-cookie-consent' ),
					'qeGroup'         => __( 'Row group in the policy table (cookies of one group are merged into one row)', 'ds-cookie-consent' ),
					'qeAppliesVi'     => __( 'Applies to (Vietnamese)', 'ds-cookie-consent' ),
					'qeAppliesEn'     => __( 'Applies to (English)', 'ds-cookie-consent' ),
					'qeSave'          => __( 'Save', 'ds-cookie-consent' ),
					'qeCancel'        => __( 'Cancel', 'ds-cookie-consent' ),
					'qeError'         => __( 'Could not save. Please try again.', 'ds-cookie-consent' ),
					/* translators: 1: pages done, 2: pages total */
					'scanProgress'    => __( 'Scanning: %1$s/%2$s pages...', 'ds-cookie-consent' ),
					/* translators: 1: cookies found, 2: new cookies */
					'scanDone'        => __( 'Scan finished: %1$s cookies set by the server, %2$s new cookies. Reloading the page...', 'ds-cookie-consent' ),
					'scanFailed'      => __( 'The scan failed. Check that the site can load itself (loopback), then try again.', 'ds-cookie-consent' ),
					'scanError'       => __( 'Could not reach the server. Please try again.', 'ds-cookie-consent' ),
					'qsError'         => __( 'Could not apply the change. Please try again.', 'ds-cookie-consent' ),
				),
			)
		);
	}
}
