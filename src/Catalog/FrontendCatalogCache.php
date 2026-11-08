<?php
/**
 * Front-end snapshot of the confirmed catalog, kept in ONE autoloaded option so a page view costs no extra query
 * beyond the options WordPress already loads (spec 11). It holds both languages, so it is not split per language.
 * Any catalog change (action `dscc_catalog_changed`) drops the snapshot; it is rebuilt once at the end of that
 * request, and lazily on the next read if that did not happen.
 *
 * Shape: {v, updated, services: [ {slug, name, category, privacy_url, blockable, cookies: [ {id, name, duration,
 * purpose, domain, group, applies} ]} ]}; `blockable` = the service has script patterns and "block until consent" on,
 * so a per-service switch in the popup would really do something where name/duration/purpose/applies are {vi, en} maps. Cookies keep insertion (id)
 * order, which is the row order of the policy table.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

use DSolutions\CookieConsent\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class FrontendCatalogCache {

	const OPTION  = 'dscc_frontend_catalog';
	const VERSION = 3;

	/**
	 * Per-request copy.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * Whether the end-of-request rebuild is already scheduled.
	 *
	 * @var bool
	 */
	private static $rebuild_scheduled = false;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'dscc_catalog_changed', array( __CLASS__, 'invalidate' ) );
		add_filter( 'dscc_catalog_services', array( __CLASS__, 'filter_services' ), 5 );
	}

	/**
	 * Filter callback: confirmed services for the popup (see Frontend\ServiceCatalog).
	 *
	 * @param array $services Services added by earlier filters.
	 * @return array
	 */
	public static function filter_services( $services ) {
		return array_merge( is_array( $services ) ? $services : array(), self::services() );
	}

	/**
	 * Confirmed services with their confirmed cookies.
	 *
	 * @return array[]
	 */
	public static function services() {
		$snapshot = self::snapshot();
		return $snapshot['services'];
	}

	/**
	 * Unix time of the newest catalog change included in the snapshot (0 when empty).
	 *
	 * @return int
	 */
	public static function updated_at() {
		$snapshot = self::snapshot();
		return (int) $snapshot['updated'];
	}

	/**
	 * Drop the snapshot and the admin badge count; rebuild once when the request ends.
	 */
	public static function invalidate() {
		self::$memo = null;
		delete_option( self::OPTION );
		delete_transient( 'dscc_new_cookies' );
		if ( ! self::$rebuild_scheduled ) {
			self::$rebuild_scheduled = true;
			add_action( 'shutdown', array( __CLASS__, 'rebuild' ) );
		}
	}

	/**
	 * Build the snapshot from the database and store it (autoloaded).
	 *
	 * @return array The snapshot.
	 */
	public static function rebuild() {
		self::$rebuild_scheduled = false;
		$snapshot                = self::build();
		if ( null === $snapshot ) {
			return array(
				'v'        => self::VERSION,
				'updated'  => 0,
				'services' => array(),
			);
		}
		update_option( self::OPTION, $snapshot, true );
		self::$memo = $snapshot;
		return $snapshot;
	}

	/**
	 * Current snapshot: request memo, then the autoloaded option, then a rebuild.
	 *
	 * @return array
	 */
	private static function snapshot() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$stored = get_option( self::OPTION );
		// An older snapshot is still served until the database upgrade (which runs on admin_init) allows a rebuild.
		$usable = is_array( $stored ) && isset( $stored['v'], $stored['services'] ) && ( self::VERSION === $stored['v'] || get_option( 'dscc_db_version' ) !== Schema::VERSION );
		if ( $usable ) {
			self::$memo = $stored;
			return $stored;
		}
		self::$memo = self::rebuild();
		return self::$memo;
	}

	/**
	 * Read confirmed services and cookies.
	 *
	 * @return array|null Null while the tables do not exist yet (plugin files updated, upgrade not run).
	 */
	private static function build() {
		global $wpdb;
		if ( get_option( 'dscc_db_version' ) !== Schema::VERSION ) {
			return null;
		}
		$services = Schema::services_table();
		$cookies  = Schema::cookies_table();

		$rows    = $wpdb->get_results( "SELECT * FROM {$services} WHERE status = 'confirmed' ORDER BY FIELD(category_key, 'necessary', 'functional', 'analytics', 'marketing'), name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, no user input.
		$by_id   = array();
		$updated = 0;
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! in_array( $row['category_key'], array( 'necessary', 'functional', 'analytics', 'marketing' ), true ) ) {
				continue; // Unclassified services are never shown to visitors.
			}
			$updated                   = max( $updated, (int) strtotime( $row['updated_at'] . ' UTC' ) );
			$by_id[ (int) $row['id'] ] = array(
				'slug'        => $row['slug'],
				'name'        => $row['name'],
				'category'    => $row['category_key'],
				'privacy_url' => $row['privacy_url'],
				'blockable'   => ! empty( $row['block_enabled'] ) && self::has_patterns( $row['script_patterns'] ),
				'cookies'     => array(),
			);
		}
		if ( $by_id ) {
			$list = $wpdb->get_results( "SELECT * FROM {$cookies} WHERE status = 'confirmed' AND service_id IN (" . implode( ',', array_keys( $by_id ) ) . ') ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- ids come from the previous query.
			foreach ( is_array( $list ) ? $list : array() as $c ) {
				$updated                                      = max( $updated, (int) strtotime( $c['updated_at'] . ' UTC' ) );
				$by_id[ (int) $c['service_id'] ]['cookies'][] = array(
					'id'       => (int) $c['id'],
					'name'     => array(
						'vi' => PatternMatcher::display_name( $c['name'], 'vi', $c['storage'] ),
						'en' => PatternMatcher::display_name( $c['name'], 'en', $c['storage'] ),
					),
					'duration' => self::both( $c['duration_label'], $c['duration_label_en'] ),
					'purpose'  => self::both( $c['purpose'], $c['purpose_en'] ),
					'domain'   => $c['domain'],
					'group'    => $c['table_group'],
					'applies'  => self::both( $c['applies_to'], $c['applies_to_en'] ),
				);
			}
		}
		return array(
			'v'        => self::VERSION,
			'updated'  => $updated,
			'services' => array_values( $by_id ),
		);
	}

	/**
	 * Does a stored script_patterns value list at least one host or pattern?
	 *
	 * @param string $json Stored JSON.
	 * @return bool
	 */
	private static function has_patterns( $json ) {
		$patterns = PatternMatcher::normalize_patterns( json_decode( (string) $json, true ) );
		return (bool) ( $patterns['hosts'] || $patterns['src_regex'] || $patterns['inline_regex'] );
	}

	/**
	 * Vietnamese/English pair; a missing English text falls back to the Vietnamese one.
	 *
	 * @param string $vi Vietnamese.
	 * @param string $en English.
	 * @return array{vi:string,en:string}
	 */
	private static function both( $vi, $en ) {
		return array(
			'vi' => (string) $vi,
			'en' => '' !== trim( (string) $en ) ? (string) $en : (string) $vi,
		);
	}
}
