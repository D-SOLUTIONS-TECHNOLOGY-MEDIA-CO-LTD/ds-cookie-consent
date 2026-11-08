<?php
/**
 * Plugin bootstrap: wires every component to WordPress hooks.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent;

use DSolutions\CookieConsent\Admin\AdminMenu;
use DSolutions\CookieConsent\Admin\BlockingTab;
use DSolutions\CookieConsent\Admin\CookiesTab;
use DSolutions\CookieConsent\Admin\TransferTab;
use DSolutions\CookieConsent\Admin\PlatformsTab;
use DSolutions\CookieConsent\Admin\ScannerTab;
use DSolutions\CookieConsent\Admin\QuickSetup;
use DSolutions\CookieConsent\Admin\ScanPanel;
use DSolutions\CookieConsent\Admin\SettingsPage;
use DSolutions\CookieConsent\Admin\SvgUploadGuard;
use DSolutions\CookieConsent\Blocker\OutputBuffer;
use DSolutions\CookieConsent\Blocker\ScriptRules;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Consent\LogEndpoint;
use DSolutions\CookieConsent\Consent\LogExporter;
use DSolutions\CookieConsent\Frontend\Assets;
use DSolutions\CookieConsent\Frontend\EmbedMode;
use DSolutions\CookieConsent\Frontend\HeadScript;
use DSolutions\CookieConsent\Frontend\Preview;
use DSolutions\CookieConsent\Frontend\Renderer;
use DSolutions\CookieConsent\Frontend\ResolvedCache;
use DSolutions\CookieConsent\Frontend\Shortcodes;
use DSolutions\CookieConsent\Integrations\Registry;
use DSolutions\CookieConsent\Scanner\BeaconEndpoint;
use DSolutions\CookieConsent\Scanner\DeepScanEndpoint;
use DSolutions\CookieConsent\Scanner\ScanRequest;
use DSolutions\CookieConsent\Scanner\ScanRunner;
use DSolutions\CookieConsent\Scanner\ScanScheduler;
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;
use DSolutions\CookieConsent\Update\GitHubUpdater;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all hooks (idempotent).
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Before anything reads the catalog: a stale schema (for example after a background update) is fixed on the first request.
		Activator::ensure_schema();
		add_action( 'upgrader_process_complete', array( Activator::class, 'after_update' ), 10, 2 );
		GitHubUpdater::register();

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( Settings::class, 'ensure_defaults' ), 1 );
		add_filter( 'sanitize_option_' . SettingsSchema::OPTION, array( SettingsSchema::class, 'enforce_limits' ) );
		add_action( 'update_option_' . SettingsSchema::OPTION, array( Settings::class, 'flush' ) );
		add_action( 'add_option_' . SettingsSchema::OPTION, array( Settings::class, 'flush' ) );

		ResolvedCache::register();
		FrontendCatalogCache::register();
		ScriptRules::register();
		LogEndpoint::register();
		Renderer::register();
		HeadScript::register();
		Assets::register();
		EmbedMode::register();
		Shortcodes::register();
		Preview::register();
		OutputBuffer::register();
		Registry::register();
		BeaconEndpoint::register();
		DeepScanEndpoint::register();
		ScanRequest::register();
		ScanRunner::register();
		ScanScheduler::register();

		if ( is_admin() ) {
			add_action( 'admin_init', array( Activator::class, 'maybe_upgrade' ) );
			add_action( 'admin_post_dscc_export_log', array( LogExporter::class, 'handle_download' ) );
			AdminMenu::register();
			SettingsPage::register();
			PlatformsTab::register();
			CookiesTab::register();
			BlockingTab::register();
			ScannerTab::register();
			TransferTab::register();
			ScanPanel::register();
			QuickSetup::register();
			SvgUploadGuard::register();
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'ds-cookie-consent', false, dirname( plugin_basename( DSCC_FILE ) ) . '/languages' );
	}
}
