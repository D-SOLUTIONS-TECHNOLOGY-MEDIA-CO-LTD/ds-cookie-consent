<?php
/**
 * Settings tab "Nền tảng": the platform picker. Admins tick the platforms used on the site, choose per platform
 * whether it is blocked until consent, add custom platforms and refresh the known-cookie database.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Catalog\KnownDbUpdater;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Catalog\ServiceRepository;

defined( 'ABSPATH' ) || exit;

final class PlatformsTab {

	const TAB = 'platforms';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_filter( 'dscc_admin_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'admin_post_dscc_save_platforms', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_dscc_add_platform', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_dscc_update_known_db', array( __CLASS__, 'handle_update_db' ) );
	}

	/**
	 * Add the tab to the settings screen.
	 *
	 * @param array $tabs Registered tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		return SettingsPage::insert_tab(
			$tabs,
			self::TAB,
			array(
				'label'  => __( 'Platforms', 'ds-cookie-consent' ),
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
		$platforms = PlatformManager::overview();
		$groups    = array();
		foreach ( array_keys( CatalogLabels::categories() ) as $key ) {
			$groups[ $key ] = array();
		}
		foreach ( $platforms as $platform ) {
			if ( isset( $groups[ $platform['category'] ] ) ) {
				$groups[ $platform['category'] ][] = $platform;
			}
		}
		$db      = KnownDb::load();
		$db_info = array(
			'version'  => $db ? $db->version() : '',
			'cookies'  => $db ? $db->cookie_count() : 0,
			'services' => $db ? count( $db->services() ) : 0,
			'url'      => KnownDbUpdater::configured_url(),
		);
		$labels  = CatalogLabels::categories();
		$enabled = count( array_filter( wp_list_pluck( $platforms, 'enabled' ) ) );
		include DSCC_DIR . 'templates/admin/tab-platforms.php';
	}

	/**
	 * Common guard: capability and nonce.
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
	 * Redirect back to the tab.
	 */
	private static function back() {
		wp_safe_redirect( SettingsPage::url( self::TAB ) );
		exit;
	}

	/**
	 * Apply the picker form: ticked slugs are enabled, shown-but-unticked ones disabled, and the block switch
	 * is stored for every ticked one. Slugs that are not platforms of this site are ignored.
	 *
	 * @param string[] $all   Slugs shown in the form.
	 * @param string[] $on    Ticked slugs.
	 * @param string[] $block Slugs whose "block until consent" switch is on.
	 */
	public static function apply( array $all, array $on, array $block ) {
		$current = PlatformManager::overview();
		foreach ( $all as $slug ) {
			if ( ! isset( $current[ $slug ] ) ) {
				continue;
			}
			if ( in_array( $slug, $on, true ) ) {
				if ( ! $current[ $slug ]['enabled'] ) {
					PlatformManager::enable( $slug );
				}
				ServiceRepository::set_block( $slug, in_array( $slug, $block, true ) );
			} elseif ( $current[ $slug ]['enabled'] ) {
				PlatformManager::disable( $slug );
			}
		}
	}

	/**
	 * Save ticks and "block until consent" switches.
	 */
	public static function handle_save() {
		self::guard( 'dscc_save_platforms' );
		$all   = self::posted_slugs( 'dscc_platform_all' );
		$on    = array_flip( self::posted_slugs( 'dscc_platform_on' ) );
		$block = isset( $_POST['dscc_platform_block'] ) && is_array( $_POST['dscc_platform_block'] ) ? array_keys( array_map( 'absint', wp_unslash( $_POST['dscc_platform_block'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); only the keys are used and they are validated against the known slugs below.
		$block = array_flip( array_map( 'sanitize_key', $block ) );

		self::apply( $all, array_keys( $on ), array_keys( $block ) );
		Flash::add( 'success', __( 'Platform list saved.', 'ds-cookie-consent' ) );
		self::back();
	}

	/**
	 * Add a custom platform.
	 */
	public static function handle_add() {
		self::guard( 'dscc_add_platform' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); every field is sanitised by PlatformManager::add_custom().
		$input  = isset( $_POST['dscc_custom'] ) && is_array( $_POST['dscc_custom'] ) ? wp_unslash( $_POST['dscc_custom'] ) : array();
		$result = PlatformManager::add_custom( $input );
		if ( is_wp_error( $result ) ) {
			Flash::add( 'error', $result->get_error_message() );
		} else {
			Flash::add( 'success', __( 'Custom platform added.', 'ds-cookie-consent' ) );
		}
		self::back();
	}

	/**
	 * Download and verify a newer known-cookie database.
	 */
	public static function handle_update_db() {
		self::guard( 'dscc_update_known_db' );
		$url = KnownDbUpdater::configured_url();
		if ( '' === $url ) {
			Flash::add( 'error', __( 'The cookie database update URL is not configured (Advanced tab).', 'ds-cookie-consent' ) );
			self::back();
		}
		$result = KnownDbUpdater::update( $url );
		if ( is_wp_error( $result ) ) {
			Flash::add( 'error', $result->get_error_message() );
		} else {
			/* translators: 1: database version, 2: number of platforms, 3: number of cookies */
			Flash::add( 'success', sprintf( __( 'Cookie database updated to version %1$s (%2$d platforms, %3$d cookies).', 'ds-cookie-consent' ), $result['version'], $result['services'], $result['cookies'] ) );
		}
		self::back();
	}

	/**
	 * Valid slugs from a posted array field.
	 *
	 * @param string $field POST field name.
	 * @return string[]
	 */
	private static function posted_slugs( $field ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in guard(); each value is passed through sanitize_key() below.
		$raw = isset( $_POST[ $field ] ) && is_array( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : array();
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', $raw ) ) ) ) );
	}
}
