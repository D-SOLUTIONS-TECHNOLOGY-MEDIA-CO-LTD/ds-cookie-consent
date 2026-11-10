<?php
/**
 * Data access for catalog services (platforms / vendors). Every write fires `dscc_catalog_changed`.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

use DSolutions\CookieConsent\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ServiceRepository {

	/** Category keys. "unclassified" is internal: blocked like marketing, never shown to visitors. */
	const CATEGORIES = array( 'necessary', 'functional', 'analytics', 'marketing', 'unclassified' );
	const STATUSES   = array( 'auto', 'confirmed', 'ignored' );
	const SOURCES    = array( 'known_db', 'scan', 'manual', 'deep_scan' );
	const MAX_SLUG   = 40;

	/** Columns a caller may write (id, created_at, updated_at are managed here). */
	const FIELDS = array( 'slug', 'name', 'category_key', 'provider_company', 'privacy_url', 'is_third_party', 'transfers_abroad', 'country', 'script_patterns', 'block_enabled', 'status', 'source', 'detected_via' );

	/**
	 * Should a service be blocked until consent by default? Analytics, marketing and unclassified: yes.
	 * Functional: only a third-party one (an embedded video or map); the site's own plugin scripts keep running.
	 *
	 * @param string $category    Category key.
	 * @param bool   $third_party Whether the service belongs to another company.
	 * @return int 1 or 0.
	 */
	public static function default_block( $category, $third_party = false ) {
		if ( 'functional' === $category ) {
			return $third_party ? 1 : 0;
		}
		return in_array( $category, array( 'analytics', 'marketing', 'unclassified' ), true ) ? 1 : 0;
	}

	/**
	 * One service by slug.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public static function find_by_slug( $slug ) {
		global $wpdb;
		$table = Schema::services_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", (string) $slug ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $row ? ServiceRow::hydrate( $row ) : null;
	}

	/**
	 * One service by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function find( $id ) {
		global $wpdb;
		$table = Schema::services_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $row ? ServiceRow::hydrate( $row ) : null;
	}

	/**
	 * All services, optionally limited to one status, ordered by name.
	 *
	 * @param string $status Status filter ('' = any).
	 * @return array[]
	 */
	public static function all( $status = '' ) {
		global $wpdb;
		$table = Schema::services_table();
		if ( '' !== $status && in_array( $status, self::STATUSES, true ) ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY name ASC", $status ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		} else {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom table, no user input.
		}
		return array_map( array( ServiceRow::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Insert a service, or update the one with the same slug (only the fields present in $data change).
	 *
	 * @param array $data Field values (see FIELDS); slug is required.
	 * @return int Service id, 0 on invalid input or failure.
	 */
	public static function upsert( array $data ) {
		global $wpdb;
		$clean = ServiceRow::sanitize( $data );
		if ( '' === $clean['slug'] ) {
			return 0;
		}
		$table    = Schema::services_table();
		$existing = self::find_by_slug( $clean['slug'] );
		$now      = current_time( 'mysql', true );
		$given    = array_intersect_key( $clean, $data );

		if ( $existing ) {
			$given = array_diff_key( $given, array( 'slug' => 1 ) );
			if ( isset( $given['block_enabled'] ) && 'necessary' === ( isset( $given['category_key'] ) ? $given['category_key'] : $existing['category_key'] ) ) {
				$given['block_enabled'] = 0;
			}
			if ( $given ) {
				$given['updated_at'] = $now;
				$wpdb->update( $table, $given, array( 'id' => $existing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
			}
			$id = (int) $existing['id'];
		} else {
			$row = array_merge( ServiceRow::defaults(), $clean );
			if ( ! array_key_exists( 'block_enabled', $data ) ) {
				$row['block_enabled'] = self::default_block( $row['category_key'], ! empty( $row['is_third_party'] ) );
			}
			$row['created_at'] = $now;
			$row['updated_at'] = $now;
			if ( ! $wpdb->insert( $table, $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- custom table.
				return 0;
			}
			$id = (int) $wpdb->insert_id;
		}
		do_action( 'dscc_catalog_changed' );
		return $id;
	}

	/**
	 * Set the status of one service.
	 *
	 * @param string $slug   Slug.
	 * @param string $status auto|confirmed|ignored.
	 * @return bool True when the service exists.
	 */
	public static function set_status( $slug, $status ) {
		if ( ! in_array( $status, self::STATUSES, true ) || ! self::find_by_slug( $slug ) ) {
			return false;
		}
		return self::upsert(
			array(
				'slug'   => $slug,
				'status' => $status,
			)
		) > 0;
	}

	/**
	 * Turn "block until consent" on or off. Necessary services are never blocked.
	 *
	 * @param string $slug  Slug.
	 * @param bool   $block Block until consent.
	 * @return bool True when the service exists.
	 */
	public static function set_block( $slug, $block ) {
		$service = self::find_by_slug( $slug );
		if ( ! $service ) {
			return false;
		}
		$block = $block && 'necessary' !== $service['category_key'];
		return self::upsert(
			array(
				'slug'          => $slug,
				'block_enabled' => $block ? 1 : 0,
			)
		) > 0;
	}

	/**
	 * Move a service to another category; "block until consent" follows the new category's default.
	 *
	 * @param int    $id       Service id.
	 * @param string $category Category key.
	 * @return bool
	 */
	public static function set_category( $id, $category ) {
		$service = self::find( $id );
		if ( ! $service || ! in_array( $category, self::CATEGORIES, true ) ) {
			return false;
		}
		if ( $service['category_key'] === $category ) {
			return true;
		}
		return self::upsert(
			array(
				'slug'          => $service['slug'],
				'category_key'  => $category,
				'block_enabled' => self::default_block( $category, ! empty( $service['is_third_party'] ) ),
			)
		) > 0;
	}

	/**
	 * The first-party service that holds cookies the admin classified without choosing a vendor.
	 *
	 * @param string $category Category key.
	 * @return int Service id.
	 */
	public static function site_service( $category ) {
		$slug = 'site-' . $category;
		$row  = self::find_by_slug( $slug );
		if ( $row ) {
			return (int) $row['id'];
		}
		return self::upsert(
			array(
				'slug'           => $slug,
				'name'           => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'category_key'   => $category,
				'is_third_party' => 0,
				'status'         => 'confirmed',
				'source'         => 'manual',
			)
		);
	}

	/**
	 * A slug derived from a name that no existing service uses and that is not reserved (known-db slugs, and the
	 * "site-*" slugs of the first-party holders).
	 *
	 * @param string   $name     Display name.
	 * @param string[] $reserved Slugs that must not be used.
	 * @return string
	 */
	public static function unique_slug( $name, array $reserved = array() ) {
		// The same sanitizer that stores the slug (ServiceRow), so two names never collapse into one stored slug.
		$base = sanitize_key( sanitize_title( $name ) );
		$base = '' === $base ? 'platform' : substr( $base, 0, self::MAX_SLUG - 4 );
		$slug = $base;
		$n    = 2;
		while ( in_array( $slug, $reserved, true ) || 0 === strpos( $slug, 'site-' ) || self::find_by_slug( $slug ) ) {
			$slug = $base . '-' . $n;
			++$n;
		}
		return $slug;
	}
}
