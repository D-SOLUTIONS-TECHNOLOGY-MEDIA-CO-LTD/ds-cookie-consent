<?php
/**
 * Builds the portable configuration document: settings, text overrides, platforms and cookies. The consent log, scan
 * runs and per-site state (media ids, page ids, policy version, alert email, observation statistics) are not part of
 * it, so the file can seed another site without carrying that site's history.
 *
 * Format (version 1):
 * { "format": "dscc-export", "version": 1, "exported_at": ISO-8601, "settings": {group: {key: value}},
 *   "texts": {"vi": {key: text}, "en": {key: text}}, "services": [..], "cookies": [..] }
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Transfer;

use DSolutions\CookieConsent\Catalog\CookieRow;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Database\Schema;
use DSolutions\CookieConsent\Settings\DefaultTexts;
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class ExportBuilder {

	const FORMAT  = 'dscc-export';
	const VERSION = 1;

	/** Settings that belong to one site and are neither exported nor imported (group => keys). */
	const SITE_BOUND = array(
		'appearance' => array( 'logo_id', 'logo_popup_id' ),
		'content'    => array( 'cookie_policy_page_id', 'privacy_page_id' ),
		'scanner'    => array( 'notify_email' ),
		'advanced'   => array( 'policy_version', 'trusted_ip_header', 'delete_data_on_uninstall' ),
	);

	/** Writable service fields in the document. */
	const SERVICE_FIELDS = array( 'slug', 'name', 'category_key', 'provider_company', 'privacy_url', 'is_third_party', 'transfers_abroad', 'country', 'script_patterns', 'block_enabled', 'status', 'source' );

	/** Writable cookie fields in the document (besides service_slug). */
	const COOKIE_FIELDS = array( 'name', 'storage', 'domain', 'path', 'duration_label', 'duration_label_en', 'duration_seconds', 'purpose', 'purpose_en', 'http_only', 'set_by', 'status', 'source', 'table_group', 'applies_to', 'applies_to_en' );

	/**
	 * Whether a settings key travels with the document.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @return bool
	 */
	public static function portable( $group, $key ) {
		return ! isset( self::SITE_BOUND[ $group ] ) || ! in_array( $key, self::SITE_BOUND[ $group ], true );
	}

	/**
	 * The document.
	 *
	 * @return array
	 */
	public static function build() {
		return array(
			'format'      => self::FORMAT,
			'version'     => self::VERSION,
			'exported_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'settings'    => self::settings(),
			'texts'       => self::texts(),
			'services'    => self::services(),
			'cookies'     => self::cookies(),
		);
	}

	/**
	 * The document as pretty JSON.
	 *
	 * @return string
	 */
	public static function to_json() {
		return (string) wp_json_encode( self::build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Portable settings.
	 *
	 * @return array
	 */
	private static function settings() {
		$out = array();
		foreach ( array_keys( SettingsSchema::fields() ) as $group ) {
			$values = Settings::group( $group );
			foreach ( SettingsSchema::fields()[ $group ] as $key => $def ) {
				if ( self::portable( $group, $key ) && array_key_exists( $key, $values ) ) {
					$out[ $group ][ $key ] = $values[ $key ];
				}
			}
		}
		return $out;
	}

	/**
	 * Text overrides of both languages (defaults are not exported).
	 *
	 * @return array
	 */
	private static function texts() {
		$out = array();
		foreach ( DefaultTexts::LANGS as $lang ) {
			$overrides = Settings::text_overrides( $lang );
			ksort( $overrides );
			$out[ $lang ] = (object) $overrides;
		}
		return $out;
	}

	/**
	 * Platforms, ordered by slug.
	 *
	 * @return array[]
	 */
	private static function services() {
		$rows = ServiceRepository::all();
		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $a['slug'], $b['slug'] );
			}
		);
		$out = array();
		foreach ( $rows as $row ) {
			$item = array();
			foreach ( self::SERVICE_FIELDS as $field ) {
				$item[ $field ] = $row[ $field ];
			}
			foreach ( array( 'is_third_party', 'transfers_abroad', 'block_enabled' ) as $flag ) {
				$item[ $flag ] = (bool) $item[ $flag ];
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * Cookies, ordered by platform and name.
	 *
	 * @return array[]
	 */
	private static function cookies() {
		global $wpdb;
		$slugs = array();
		foreach ( ServiceRepository::all() as $service ) {
			$slugs[ (int) $service['id'] ] = $service['slug'];
		}
		$table = Schema::cookies_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name from the trusted prefix, no user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC, storage ASC, domain ASC", ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row  = CookieRow::hydrate( $row );
			$item = array( 'service_slug' => isset( $slugs[ $row['service_id'] ] ) ? $slugs[ $row['service_id'] ] : '' );
			foreach ( self::COOKIE_FIELDS as $field ) {
				$item[ $field ] = $row[ $field ];
			}
			$item['http_only'] = (bool) $item['http_only'];
			$out[]             = $item;
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return strcmp( $a['service_slug'] . "\0" . $a['name'] . "\0" . $a['storage'] . "\0" . $a['domain'], $b['service_slug'] . "\0" . $b['name'] . "\0" . $b['storage'] . "\0" . $b['domain'] );
			}
		);
		return $out;
	}
}
