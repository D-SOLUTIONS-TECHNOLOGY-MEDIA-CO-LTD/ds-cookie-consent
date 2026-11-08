<?php
/**
 * Pre-resolved logo URLs/dimensions and policy page URLs, kept in the autoloaded option dscc_resolved
 * so a front-end render needs no attachment or permalink lookups (zero extra queries).
 *
 * The cache is rebuilt when settings change, when a referenced post/attachment changes, and lazily
 * when its fingerprint (the referenced ids/urls plus the site url) no longer matches.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class ResolvedCache {

	const OPTION = 'dscc_resolved';

	/**
	 * Post id treated as already gone while its deletion is in progress.
	 *
	 * @var int
	 */
	private static $ignored_id = 0;

	/**
	 * Register the invalidation hooks.
	 */
	public static function register() {
		add_action( 'update_option_' . SettingsSchema::OPTION, array( __CLASS__, 'refresh' ) );
		add_action( 'add_option_' . SettingsSchema::OPTION, array( __CLASS__, 'refresh' ) );
		foreach ( array( 'save_post', 'edit_attachment', 'attachment_updated', 'trashed_post', 'untrashed_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'refresh_for_post' ) );
		}
		// Deletion hooks fire while object caches can still hold the removed post, so its id is excluded explicitly.
		add_action( 'delete_attachment', array( __CLASS__, 'refresh_without_post' ) );
		add_action( 'deleted_post', array( __CLASS__, 'refresh_without_post' ) );
		add_action( 'permalink_structure_changed', array( __CLASS__, 'refresh' ) );
		add_action( 'switch_theme', array( __CLASS__, 'refresh' ) );
		add_action( 'customize_save_after', array( __CLASS__, 'refresh' ) );
	}

	/**
	 * Resolved values for the saved settings. Rebuilds (and stores) when missing or stale.
	 *
	 * @return array {logo: array, logo_popup: array, cookie_url: string, privacy_url: string}
	 */
	public static function get() {
		$stored = get_option( self::OPTION );
		$key    = self::fingerprint();
		if ( is_array( $stored ) && isset( $stored['key'], $stored['data'] ) && $stored['key'] === $key ) {
			return $stored['data'];
		}
		return self::refresh();
	}

	/**
	 * Recompute from the saved settings and store the result.
	 *
	 * @return array
	 */
	public static function refresh() {
		Settings::flush();
		$data = self::resolve( Settings::group( 'appearance' ), Settings::group( 'content' ) );
		update_option(
			self::OPTION,
			array(
				'key'  => self::fingerprint(),
				'data' => $data,
			),
			true
		);
		return $data;
	}

	/**
	 * Rebuild only when the changed post is one the cache points at.
	 *
	 * @param int $post_id Post or attachment id.
	 */
	public static function refresh_for_post( $post_id ) {
		if ( in_array( (int) $post_id, self::referenced_ids(), true ) ) {
			self::refresh();
		}
	}

	/**
	 * Rebuild as if the given post no longer existed (used while it is being deleted).
	 *
	 * @param int $post_id Post or attachment id.
	 */
	public static function refresh_without_post( $post_id ) {
		if ( in_array( (int) $post_id, self::referenced_ids(), true ) ) {
			self::$ignored_id = (int) $post_id;
			self::refresh();
			self::$ignored_id = 0;
		}
	}

	/**
	 * Resolve from explicit settings (live DB lookups; used for the cache and the admin preview).
	 *
	 * @param array $appearance Appearance settings.
	 * @param array $content    Content settings.
	 * @return array
	 */
	public static function resolve( array $appearance, array $content ) {
		$banner = (int) $appearance['logo_id'];
		$popup  = (int) $appearance['logo_popup_id'];
		return array(
			'logo'        => self::logo( $banner ),
			'logo_popup'  => self::logo( $popup ? $popup : $banner ),
			'cookie_url'  => self::policy_url( (int) $content['cookie_policy_page_id'], (string) $content['cookie_policy_url'] ),
			'privacy_url' => self::policy_url( (int) $content['privacy_page_id'], (string) $content['privacy_url'] ),
		);
	}

	/**
	 * Ids the cached values depend on.
	 *
	 * @return int[]
	 */
	private static function referenced_ids() {
		$a   = Settings::group( 'appearance' );
		$c   = Settings::group( 'content' );
		$ids = array( (int) $a['logo_id'], (int) $a['logo_popup_id'], (int) $c['cookie_policy_page_id'], (int) $c['privacy_page_id'], (int) get_theme_mod( 'custom_logo' ) );
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Hash of everything the resolved values are derived from (all autoloaded, no queries).
	 *
	 * @return string
	 */
	private static function fingerprint() {
		$a = Settings::group( 'appearance' );
		$c = Settings::group( 'content' );
		return md5(
			implode(
				'|',
				array(
					(int) $a['logo_id'],
					(int) $a['logo_popup_id'],
					(int) $c['cookie_policy_page_id'],
					(string) $c['cookie_policy_url'],
					(int) $c['privacy_page_id'],
					(string) $c['privacy_url'],
					home_url(),
				)
			)
		);
	}

	/**
	 * Logo attachment (or the theme custom logo as fallback): url plus pixel size when known.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array {url: string, w: int, h: int}
	 */
	private static function logo( $attachment_id ) {
		foreach ( array( (int) $attachment_id, (int) get_theme_mod( 'custom_logo' ) ) as $id ) {
			if ( $id <= 0 || $id === self::$ignored_id ) {
				continue;
			}
			$url = wp_get_attachment_url( $id );
			if ( $url ) {
				$meta = wp_get_attachment_metadata( $id );
				return array(
					'url' => $url,
					'w'   => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : 0,
					'h'   => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : 0,
				);
			}
		}
		return array(
			'url' => '',
			'w'   => 0,
			'h'   => 0,
		);
	}

	/**
	 * Permalink of a published page, else the explicit URL (site-relative paths made absolute).
	 *
	 * @param int    $page_id Page id.
	 * @param string $url     URL fallback.
	 * @return string
	 */
	private static function policy_url( $page_id, $url ) {
		if ( $page_id > 0 && $page_id !== self::$ignored_id ) {
			$link = get_permalink( $page_id );
			if ( $link && 'publish' === get_post_status( $page_id ) ) {
				return $link;
			}
		}
		if ( '' !== $url && '/' === $url[0] ) {
			return home_url( $url );
		}
		return $url;
	}
}
