<?php
/**
 * Activation / deactivation / upgrade routines.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent;

use DSolutions\CookieConsent\Database\Schema;
use DSolutions\CookieConsent\Scanner\ScanScheduler;
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Support\OptionLock;

defined( 'ABSPATH' ) || exit;

final class Activator {

	const CAP_VIEW_LOG = 'dscc_view_log';

	/**
	 * Plugin activation: tables, capability, default options, cron.
	 */
	public static function activate() {
		self::install();
		if ( ! wp_next_scheduled( 'dscc_cleanup_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dscc_cleanup_log' );
		}
	}

	/**
	 * Plugin deactivation: stop scheduled work only; data is kept.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'dscc_cleanup_log' );
		ScanScheduler::clear();
	}

	/**
	 * Idempotent install/upgrade (also called from admin_init when the stored version is stale).
	 */
	public static function install() {
		Schema::install();
		self::grant_capability();
		Settings::ensure_defaults();
		update_option( 'dscc_db_version', Schema::VERSION, true );
	}

	/**
	 * Option that serialises concurrent schema upgrades (value: unix time the upgrade started).
	 */
	const UPGRADE_LOCK = 'dscc_db_upgrade_lock';

	/** A lock older than this many seconds belongs to a request that died; it is taken over. */
	const LOCK_TTL = 120;

	/**
	 * Cheap check run on every request: compare the autoloaded schema version with the code version and upgrade
	 * when they differ. Runs before any other component reads the catalog, so after a background update the
	 * blocker never works from tables or options of the previous release while waiting for an admin visit.
	 *
	 * @return bool True when an upgrade ran (and succeeded) in this call.
	 */
	public static function ensure_schema() {
		if ( get_option( 'dscc_db_version' ) === Schema::VERSION ) {
			return false;
		}
		if ( ! OptionLock::acquire( self::UPGRADE_LOCK, self::LOCK_TTL ) ) {
			return false;
		}
		try {
			self::install();
		} catch ( \Throwable $error ) {
			// The lock is kept on purpose: it makes the next attempts wait LOCK_TTL seconds instead of failing on every
			// request, and the failure is written to the log once per attempt window.
			error_log( 'DS Cookie Consent: schema upgrade failed, retrying later: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for the site owner.
			return false;
		}
		OptionLock::release( self::UPGRADE_LOCK );
		return true;
	}

	/**
	 * Schema upgrade plus the daily cleanup schedule (admin screens).
	 */
	public static function maybe_upgrade() {
		self::ensure_schema();
		if ( ! wp_next_scheduled( 'dscc_cleanup_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dscc_cleanup_log' );
		}
	}

	/**
	 * After this plugin was updated, the running request still holds the old code. Drop the cached release
	 * answer and poke the site once so a fresh PHP process loads the new code and upgrades the schema now,
	 * instead of waiting for the next visitor.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $extra    Hook data (action, type, plugins).
	 */
	public static function after_update( $upgrader, $extra ) {
		unset( $upgrader );
		if ( ! is_array( $extra ) || 'update' !== ( $extra['action'] ?? '' ) || 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}
		$plugins = isset( $extra['plugins'] ) ? (array) $extra['plugins'] : ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() );
		if ( ! in_array( plugin_basename( DSCC_FILE ), $plugins, true ) ) {
			return;
		}
		delete_site_transient( \DSolutions\CookieConsent\Update\GitHubUpdater::CACHE_KEY );
		wp_remote_get(
			add_query_arg( 'dscc_upgrade', (string) time(), home_url( '/' ) ), // A fresh query string gets past a page cache.
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			)
		);
	}

	/**
	 * Give administrators the log-viewing capability.
	 */
	private static function grant_capability() {
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAP_VIEW_LOG ) ) {
			$role->add_cap( self::CAP_VIEW_LOG );
		}
	}
}
