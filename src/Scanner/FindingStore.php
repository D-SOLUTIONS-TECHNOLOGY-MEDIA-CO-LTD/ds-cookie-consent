<?php
/**
 * Where scanner findings meet the catalog. Every source (server scan, beacon, headless deep scan) hands its
 * observations to merge_cookies() and detected platforms to merge_detection(); this class classifies them (spec 6.3),
 * creates the rows and keeps the seen_* statistics. It never stores a cookie VALUE.
 *
 * Rules:
 *  - a cookie that already has a row (any status, any domain; exact name before pattern) only gets its statistics
 *    updated, so an admin's decisions (confirmed, ignored, edited texts) are never overwritten;
 *  - a new cookie of a known platform gets that platform (status auto) and the known texts, status auto;
 *  - the cookies WordPress, WooCommerce and PHP need (CookieSafelist) are necessary, status auto, whatever else matches;
 *  - anything else is unclassified, status new: blocked like marketing and hidden from visitors until classified;
 *  - statistics updates do not fire `dscc_catalog_changed` (no cache churn); creating a row does.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Blocker\CookieSafelist;
use DSolutions\CookieConsent\Catalog\Classifier;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Catalog\PatternMatcher;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Consent\LogValidator;
use DSolutions\CookieConsent\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class FindingStore {

	/** First-party holders of cookies without a vendor. */
	const HOLDER_UNCLASSIFIED = 'site-unclassified';
	const HOLDER_NECESSARY    = 'site-necessary';

	/**
	 * Cap on rows waiting for review that did NOT come from the server scan (beacon, deep scan): a flood of odd names
	 * cannot grow the table without limit. The server scan is exempt, so noise can never hide its findings.
	 */
	const MAX_NEW = 300;

	/** Counter of findings refused by that cap (not autoloaded). */
	const DROPPED_OPTION = 'dscc_findings_dropped';

	/** Sample URLs kept per cookie. */
	const MAX_URLS = 20;

	/** Who set a cookie: the more certain source wins. */
	const SET_BY_RANK = array(
		'unknown' => 1,
		'client'  => 2,
		'server'  => 3,
	);

	/** Rows per status, best first, when several rows carry the same name. */
	const STATUS_RANK = array(
		'confirmed' => 4,
		'auto'      => 3,
		'new'       => 2,
		'ignored'   => 1,
	);

	/**
	 * Per-request memo of the number of rows waiting for review.
	 *
	 * @var int|null
	 */
	private static $new_count = null;

	/**
	 * Merge cookie / web-storage observations into the catalog.
	 *
	 * Observation keys: name (required), storage (cookie|localStorage|sessionStorage..., default cookie), domain, path,
	 * max_age (seconds, null = session), has_lifetime (bool: max_age is known), http_only, set_by (server|client|unknown),
	 * url (page path; the query string is dropped).
	 * Context keys: source (scan|deep_scan, default scan), via (server|beacon|deep, default server), max_new (rows this
	 * call may create).
	 *
	 * @param array[] $observations Observations.
	 * @param array   $context      Context.
	 * @return array[] One entry per distinct (storage, name): name, storage, id, created, status, category,
	 *                 service_slug, match (confirmed|existing|known_exact|known_pattern|safelist|unclassified|capped).
	 */
	public static function merge_cookies( array $observations, array $context = array() ) {
		$context    = array_merge(
			array(
				'source'  => 'scan',
				'via'     => 'server',
				'max_new' => PHP_INT_MAX,
			),
			$context
		);
		$by_storage = array();
		foreach ( $observations as $obs ) {
			$obs = self::normalise( $obs );
			if ( $obs ) {
				$by_storage[ $obs['storage'] ][ $obs['name'] ] = $obs;
			}
		}
		$known      = KnownDb::load();
		$classifier = new Classifier(
			$known,
			static function () {
				return null; // The catalog was consulted by this class already.
			}
		);
		$results    = array();
		$created    = 0;
		$dropped    = 0;
		foreach ( $by_storage as $storage => $list ) {
			$rows = CookieLookup::rows( array_keys( $list ), $storage );
			foreach ( $list as $name => $obs ) {
				$row = CookieLookup::find( $rows, $name );
				if ( $row ) {
					self::touch( $row, $obs );
					$results[] = self::result_for_row( $row, false, 'existing' );
					continue;
				}
				if ( $created >= (int) $context['max_new'] || self::new_rows_capped( $context['via'] ) ) {
					++$dropped;
					$results[] = array(
						'name'         => $name,
						'storage'      => $storage,
						'id'           => 0,
						'created'      => false,
						'status'       => '',
						'category'     => 'unclassified',
						'service_slug' => '',
						'match'        => 'capped',
					);
					continue;
				}
				$result = self::create( $obs, $classifier, $context );
				if ( $result['created'] ) {
					++$created;
					self::$new_count = null === self::$new_count ? null : self::$new_count + ( 'new' === $result['status'] ? 1 : 0 );
				}
				$results[] = $result;
			}
		}
		if ( $dropped ) {
			update_option( self::DROPPED_OPTION, (int) get_option( self::DROPPED_OPTION, 0 ) + $dropped, false );
		}
		return $results;
	}

	/**
	 * Findings refused since the last reset because a cap was reached (shown in the scan panel).
	 *
	 * @return int
	 */
	public static function dropped_count() {
		return (int) get_option( self::DROPPED_OPTION, 0 );
	}

	/**
	 * Forget the dropped-findings counter.
	 */
	public static function clear_dropped() {
		delete_option( self::DROPPED_OPTION );
	}

	/**
	 * Record that a known platform is present on the site (found in HTML, in a Tag Manager container or by a beacon).
	 * The platform is created with status auto when the site has no row for it; an ignored platform is left alone.
	 *
	 * @param string $slug Known-database service slug.
	 * @param string $via  html|gtm|beacon|deep|server.
	 * @return int Service id, 0 when the slug is not in the known database.
	 */
	public static function merge_detection( $slug, $via ) {
		$known = KnownDb::load();
		$def   = $known ? $known->service( (string) $slug ) : null;
		if ( ! $def ) {
			return 0;
		}
		$row = KnownServiceRegistry::ensure( $def, $via );
		return $row ? (int) $row['id'] : 0;
	}

	/**
	 * Add a detection source to a platform the site already has. Never creates a platform: used for data a visitor's
	 * browser reports, which anyone can forge.
	 *
	 * @param string $slug Known-database service slug.
	 * @param string $via  Detection source.
	 * @return bool True when the site has the platform.
	 */
	public static function note_detection( $slug, $via ) {
		$known = KnownDb::load();
		$def   = $known ? $known->service( (string) $slug ) : null;
		return $def && null !== KnownServiceRegistry::ensure( $def, $via, false );
	}

	/**
	 * Classify names without writing anything (the compliance report and tests).
	 *
	 * @param string[] $names   Names.
	 * @param string   $storage Storage kind.
	 * @return array<string,array> name => {category, service_slug, status, id}
	 */
	public static function resolve( array $names, $storage = 'cookie' ) {
		return CookieLookup::resolve( $names, $storage );
	}

	/**
	 * Validate and complete one observation.
	 *
	 * @param mixed $obs Raw observation.
	 * @return array|null
	 */
	private static function normalise( $obs ) {
		if ( ! is_array( $obs ) || ! isset( $obs['name'] ) || ! is_string( $obs['name'] ) ) {
			return null;
		}
		$name = trim( $obs['name'] );
		if ( ! BeaconValidator::literal_name( $name ) ) {
			return null; // Observed names are literal: a "*" or "?" would be stored as a pattern matching other cookies.
		}
		$storage = isset( $obs['storage'] ) && in_array( $obs['storage'], CookieRepository::STORAGES, true ) ? $obs['storage'] : 'cookie';
		$set_by  = isset( $obs['set_by'] ) && isset( self::SET_BY_RANK[ $obs['set_by'] ] ) ? $obs['set_by'] : 'unknown';
		return array(
			'name'         => $name,
			'storage'      => $storage,
			'domain'       => isset( $obs['domain'] ) ? strtolower( preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) $obs['domain'] ) ) : '',
			'path'         => isset( $obs['path'] ) && is_string( $obs['path'] ) ? substr( $obs['path'], 0, 100 ) : '/',
			'max_age'      => isset( $obs['max_age'] ) && is_numeric( $obs['max_age'] ) && (int) $obs['max_age'] > 0 ? (int) $obs['max_age'] : null,
			'has_lifetime' => ! empty( $obs['has_lifetime'] ),
			'http_only'    => ! empty( $obs['http_only'] ),
			'set_by'       => $set_by,
			'url'          => isset( $obs['url'] ) ? LogValidator::path_only( $obs['url'] ) : '',
		);
	}

	/**
	 * Update the seen statistics of an existing row (no catalog change event).
	 *
	 * @param array $row Row from load_rows().
	 * @param array $obs Observation.
	 */
	private static function touch( array $row, array $obs ) {
		global $wpdb;
		$urls   = self::merge_urls( $row['seen_on_urls'], $obs['url'] );
		$set_by = self::SET_BY_RANK[ $obs['set_by'] ] > ( isset( self::SET_BY_RANK[ $row['set_by'] ] ) ? self::SET_BY_RANK[ $row['set_by'] ] : 1 ) ? $obs['set_by'] : $row['set_by'];
		$now    = current_time( 'mysql', true );
		$table  = Schema::cookies_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET last_seen_at = %s, first_seen_at = COALESCE(first_seen_at, %s), seen_count = seen_count + 1, seen_on_urls = %s, set_by = %s, http_only = %d WHERE id = %d", $now, $now, $urls, $set_by, ( (int) $row['http_only'] || $obs['http_only'] ) ? 1 : 0, (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table; statistics only, so the catalog caches stay valid.
	}

	/**
	 * Create the row for a new cookie.
	 *
	 * @param array      $obs        Observation.
	 * @param Classifier $classifier Known-database classifier.
	 * @param array      $context    Merge context.
	 * @return array Result entry.
	 */
	private static function create( array $obs, Classifier $classifier, array $context ) {
		$cls    = $classifier->classify(
			array(
				'name'    => $obs['name'],
				'storage' => $obs['storage'],
			)
		);
		$safe   = CookieSafelist::matches( $obs['name'], CookieSafelist::patterns() );
		$known  = in_array( $cls['match'], array( 'known_exact', 'known_pattern' ), true ) && ( ! $safe || in_array( $cls['category'], array( 'necessary', 'functional' ), true ) );
		$source = 'deep_scan' === $context['source'] ? 'deep_scan' : 'scan';

		// A visitor's browser may report any name: it never creates a platform. A known cookie of a platform the site does
		// not have yet becomes a "new" row that carries the known purpose as a hint.
		$service = $known ? KnownServiceRegistry::ensure( $cls['known_service'], $context['via'], 'beacon' !== $context['via'] ) : null;
		if ( $known && ! $service && 'beacon' !== $context['via'] ) {
			return self::failed( $obs );
		}
		if ( $known && $service ) {
			$fields           = PlatformManager::cookie_fields( (int) $service['id'], $cls['known_cookie'] );
			$fields['status'] = 'ignored' === $service['status'] ? 'ignored' : 'auto';
			$fields['source'] = 'known_db';
			$category         = $service['category_key'];
			$slug             = $service['slug'];
			$match            = $cls['match'];
		} else {
			$hint            = $known && ! $service ? PlatformManager::cookie_fields( 0, $cls['known_cookie'] ) : array();
			$holder_category = $safe ? 'necessary' : 'unclassified';
			$service_id      = KnownServiceRegistry::holder( $holder_category );
			$fields          = array(
				'service_id' => $service_id,
				'name'       => $obs['name'],
				'storage'    => $obs['storage'],
				'domain'     => $obs['domain'],
				'path'       => $obs['path'],
				'status'     => $safe ? 'auto' : 'new',
				'source'     => $source,
			);
			foreach ( array( 'purpose', 'purpose_en', 'duration_label', 'duration_label_en', 'duration_seconds' ) as $key ) {
				if ( isset( $hint[ $key ] ) && ! isset( $fields[ $key ] ) ) {
					$fields[ $key ] = $hint[ $key ];
				}
			}
			if ( $obs['has_lifetime'] ) {
				$fields['duration_seconds']  = $obs['max_age'];
				$fields['duration_label']    = DurationLabel::label( $obs['max_age'], 'vi' );
				$fields['duration_label_en'] = DurationLabel::label( $obs['max_age'], 'en' );
			}
			$category = $holder_category;
			$slug     = $safe ? self::HOLDER_NECESSARY : self::HOLDER_UNCLASSIFIED;
			$match    = $safe ? 'safelist' : 'unclassified';
		}
		$fields['set_by']    = $obs['set_by'];
		$fields['http_only'] = $obs['http_only'] ? 1 : 0;

		$id = CookieRepository::upsert( $fields );
		if ( ! $id ) {
			return self::failed( $obs );
		}
		global $wpdb;
		$now   = current_time( 'mysql', true );
		$table = Schema::cookies_table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET first_seen_at = %s, last_seen_at = %s, seen_count = 1, seen_on_urls = %s WHERE id = %d", $now, $now, self::merge_urls( '', $obs['url'] ), $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.

		return array(
			'name'         => $obs['name'],
			'storage'      => $obs['storage'],
			'id'           => $id,
			'created'      => true,
			'status'       => $fields['status'],
			'category'     => $category,
			'service_slug' => $slug,
			'match'        => $match,
		);
	}

	/**
	 * Result entry for a row that could not be created.
	 *
	 * @param array $obs Observation.
	 * @return array
	 */
	private static function failed( array $obs ) {
		return array(
			'name'         => $obs['name'],
			'storage'      => $obs['storage'],
			'id'           => 0,
			'created'      => false,
			'status'       => '',
			'category'     => 'unclassified',
			'service_slug' => '',
			'match'        => 'failed',
		);
	}

	/**
	 * Result entry for an existing row.
	 *
	 * @param array  $row     Row from load_rows().
	 * @param bool   $created Whether this call created it.
	 * @param string $kind    Match kind.
	 * @return array
	 */
	private static function result_for_row( array $row, $created, $kind ) {
		return array(
			'name'         => $row['name'],
			'storage'      => $row['storage'],
			'id'           => (int) $row['id'],
			'created'      => $created,
			'status'       => $row['status'],
			'category'     => $row['category'],
			'service_slug' => (string) $row['service_slug'],
			'match'        => $kind,
		);
	}

	/**
	 * Add a page path to the stored sample list (at most MAX_URLS, no duplicates).
	 *
	 * @param string $stored Stored JSON.
	 * @param string $url    Path to add.
	 * @return string JSON.
	 */
	private static function merge_urls( $stored, $url ) {
		$list = json_decode( (string) $stored, true );
		$list = is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
		if ( '' !== $url && ! in_array( $url, $list, true ) && count( $list ) < self::MAX_URLS ) {
			$list[] = $url;
		}
		return (string) wp_json_encode( $list );
	}

	/**
	 * Has this source reached its cap on rows waiting for review? The server scan has none.
	 *
	 * @param string $via Detection source.
	 * @return bool
	 */
	private static function new_rows_capped( $via ) {
		if ( 'server' === $via ) {
			return false;
		}
		if ( null === self::$new_count ) {
			global $wpdb;
			$table           = Schema::cookies_table();
			self::$new_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'new' AND set_by <> 'server'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table, constant SQL.
		}
		return self::$new_count >= self::MAX_NEW;
	}

	/**
	 * Forget per-request memos (tests).
	 */
	public static function reset() {
		self::$new_count = null;
	}
}
