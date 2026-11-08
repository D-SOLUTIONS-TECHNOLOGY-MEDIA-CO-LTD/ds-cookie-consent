<?php
/**
 * Integrations with other plugins. Each one is detected once; when its plugin is absent none of its hooks run.
 * `detected()` of each class may be forced from tests through the filter `dscc_integration_detected`.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Integrations;

use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/**
	 * Integration classes: slug => class. Each has detected(), register(), label() and note().
	 *
	 * @return array<string,string>
	 */
	public static function classes() {
		return array(
			'meta-pixel'      => MetaPixel::class,
			'pixelyoursite'   => PixelYourSite::class,
			'site-kit'        => SiteKit::class,
			'wp-consent-api'  => WpConsentApi::class,
			'litespeed-cache' => LiteSpeedCache::class,
			'wp-rocket'       => WpRocket::class,
		);
	}

	/**
	 * Hook every detected integration (plugins_loaded). Meta Pixel registers earlier, see MetaPixel::register_early().
	 */
	public static function register() {
		foreach ( self::classes() as $slug => $class ) {
			if ( self::detected( $slug ) ) {
				call_user_func( array( $class, 'register' ) );
			}
		}
	}

	/**
	 * Is the plugin behind an integration present?
	 *
	 * @param string $slug Integration slug.
	 * @return bool
	 */
	public static function detected( $slug ) {
		$classes = self::classes();
		if ( ! isset( $classes[ $slug ] ) ) {
			return false;
		}
		return (bool) apply_filters( 'dscc_integration_detected', call_user_func( array( $classes[ $slug ], 'detected' ) ), $slug );
	}

	/**
	 * True in block mode. The observe-only mode must not change what browsers or servers do about tracking, so
	 * integrations that suppress tracking stay off in it.
	 *
	 * @return bool
	 */
	public static function blocking_mode() {
		return 'block' === Settings::get( 'blocking', 'mode', 'block' );
	}

	/**
	 * Is a plugin file in the list of active plugins (read without loading the plugin)?
	 *
	 * @param string $basename Plugin basename such as "folder/file.php".
	 * @return bool
	 */
	public static function plugin_active( $basename ) {
		if ( in_array( $basename, (array) get_option( 'active_plugins', array() ), true ) ) {
			return true;
		}
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			return isset( $network[ $basename ] );
		}
		return false;
	}

	/**
	 * Rows for the admin tab: label, detected, note.
	 *
	 * @return array[]
	 */
	public static function status() {
		$rows = array();
		foreach ( self::classes() as $slug => $class ) {
			$rows[] = array(
				'label'    => call_user_func( array( $class, 'label' ) ),
				'detected' => self::detected( $slug ),
				'note'     => call_user_func( array( $class, 'note' ) ),
			);
		}
		return $rows;
	}

	/**
	 * Flags for the browser script (#dscc-data "int").
	 *
	 * @return array{wpc:int,fbs:int}
	 */
	public static function client_flags() {
		return array(
			'wpc' => self::detected( 'wp-consent-api' ) && self::blocking_mode() ? 1 : 0,
			'fbs' => self::detected( 'meta-pixel' ) && MetaPixel::holding() ? 1 : 0,
		);
	}
}
