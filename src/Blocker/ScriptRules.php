<?php
/**
 * Blocking rules compiled from the service catalog and kept in ONE autoloaded option, so a page view needs no query.
 * A catalog change drops the snapshot (action `dscc_catalog_changed`); it is rebuilt once at the end of that request
 * and lazily on the next read otherwise.
 *
 * Snapshot: {v, rules:[ {s slug, c category, b block_enabled, h hosts[], r src regexes[], i inline regexes[]} ],
 * cookies:[ [name, storage, slug, category] ]}. Regexes are validated here, so matching never re-checks them.
 * Rules cover confirmed and auto services; "cookies" only services with "block until consent" on, and only cookies the
 * admin has not ignored: confirmed and auto ones, plus the scanner's new, unclassified COOKIES (blocked like marketing
 * until the admin classifies them; the safelist keeps the cookies the site needs out of this). Unclassified web storage
 * keys are not purged: they hold the site's own state and are never sent anywhere by themselves.
 *
 * Settings (mode, GTM mode, unclassified) are applied at read time by active(), so saving them needs no rebuild.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

use DSolutions\CookieConsent\Catalog\PatternMatcher;
use DSolutions\CookieConsent\Database\Schema;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class ScriptRules {

	const OPTION  = 'dscc_block_rules';
	const VERSION = 2;

	/** The tag manager has its own mode (blocking.gtm_mode) instead of the per-platform switch. */
	const GTM_SLUG = 'google-tag-manager';

	/** Never blocked, whatever category the admin gave them: forms and the anti-abuse widgets they depend on. */
	const NEVER_BLOCK = array( 'contact-form-7', 'elementor', 'cloudflare', 'cloudflare-turnstile', 'google-recaptcha', 'ds-cookie-consent' );

	/** Cookie rows are capped so the autoloaded snapshot stays small. */
	const MAX_COOKIES = 400;

	/** Of those, at most this many may be new, unclassified cookies (they never push out confirmed or automatic ones). */
	const MAX_NEW_COOKIES = 100;

	/**
	 * Per-request copy.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * Whether the end-of-request rebuild is scheduled.
	 *
	 * @var bool
	 */
	private static $rebuild_scheduled = false;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'dscc_catalog_changed', array( __CLASS__, 'invalidate' ) );
	}

	/**
	 * Drop the snapshot; rebuild once when the request ends.
	 */
	public static function invalidate() {
		self::$memo = null;
		delete_option( self::OPTION );
		if ( ! self::$rebuild_scheduled ) {
			self::$rebuild_scheduled = true;
			add_action( 'shutdown', array( __CLASS__, 'rebuild' ) );
		}
	}

	/**
	 * Build the snapshot and store it (autoloaded).
	 *
	 * @return array
	 */
	public static function rebuild() {
		self::$rebuild_scheduled = false;
		$snapshot                = self::build();
		if ( null === $snapshot ) {
			return self::empty_snapshot();
		}
		update_option( self::OPTION, $snapshot, true );
		self::$memo = $snapshot;
		return $snapshot;
	}

	/**
	 * Raw snapshot.
	 *
	 * @return array
	 */
	public static function snapshot() {
		if ( null !== self::$memo ) {
			return self::$memo;
		}
		$stored = get_option( self::OPTION );
		if ( is_array( $stored ) && isset( $stored['v'], $stored['rules'], $stored['cookies'] ) && self::VERSION === $stored['v'] ) {
			self::$memo = $stored;
			return $stored;
		}
		self::$memo = self::rebuild();
		return self::$memo;
	}

	/**
	 * Did the list of new, unclassified cookies hit its cap when the snapshot was built?
	 *
	 * @return bool
	 */
	public static function new_cookies_capped() {
		$snapshot = self::snapshot();
		return ! empty( $snapshot['capped'] );
	}

	/**
	 * Forget the per-request copy (tests).
	 */
	public static function flush() {
		self::$memo = null;
	}

	/**
	 * Rules and cookies that apply now, with the blocking settings applied:
	 * necessary and never-block services are out; a hidden functional category is out; unclassified services become
	 * marketing (or stay out when `blocking.block_unclassified` is off); the tag manager is blocked until analytics or
	 * marketing only in `block` mode.
	 *
	 * @return array{rules:array[],cookies:array[]} Rules: {s,c,h,r,i}; cookies: [name, storage, slug, category].
	 */
	public static function active() {
		$snapshot = self::snapshot();
		$blocking = Settings::group( 'blocking' );
		$show_fn  = ! empty( Settings::get( 'advanced', 'show_functional' ) );
		$rules    = array();
		$map      = array();
		foreach ( $snapshot['rules'] as $rule ) {
			$slug = $rule['s'];
			if ( in_array( $slug, self::NEVER_BLOCK, true ) ) {
				continue;
			}
			if ( self::GTM_SLUG === $slug ) {
				if ( 'block' !== $blocking['gtm_mode'] ) {
					continue;
				}
				$category = 'analytics marketing';
			} else {
				$category = self::effective_category( $rule['c'], $rule['b'], $blocking, $show_fn );
				if ( '' === $category ) {
					continue;
				}
			}
			$map[ $slug ] = $category;
			$rules[]      = array(
				's' => $slug,
				'c' => $category,
				'h' => $rule['h'],
				'r' => $rule['r'],
				'i' => $rule['i'],
			);
		}
		$cookies = array();
		$keep    = CookieSafelist::patterns();
		$report  = 'report' === $blocking['unclassified_cookie_mode'];
		foreach ( $snapshot['cookies'] as $row ) {
			if ( ( $report && ! empty( $row[4] ) ) || in_array( $row[2], self::NEVER_BLOCK, true ) || CookieSafelist::matches( $row[0], $keep ) ) {
				continue;
			}
			$category = self::effective_category( $row[3], 1, $blocking, $show_fn );
			if ( '' !== $category && self::GTM_SLUG !== $row[2] ) {
				$cookies[] = array( $row[0], $row[1], $row[2], $category );
			}
		}
		return array(
			'rules'   => $rules,
			'cookies' => $cookies,
		);
	}

	/**
	 * Cookie and storage names to delete when their service is not allowed, grouped by service, for the client:
	 * [ {s: slug, c: category, k: [ [name, storage], ... ]} ].
	 *
	 * @return array[]
	 */
	public static function purge_list() {
		$groups = array();
		foreach ( self::active()['cookies'] as $row ) {
			if ( ! isset( $groups[ $row[2] ] ) ) {
				$groups[ $row[2] ] = array(
					's' => $row[2],
					'c' => $row[3],
					'k' => array(),
				);
			}
			$groups[ $row[2] ]['k'][] = array( $row[0], $row[1] );
		}
		return array_values( $groups );
	}

	/**
	 * Category a service is blocked under, or '' when it is not blocked.
	 *
	 * @param string $category Stored category.
	 * @param int    $block    block_enabled.
	 * @param array  $blocking Blocking settings.
	 * @param bool   $show_fn  Whether the functional category is visible to visitors.
	 * @return string
	 */
	private static function effective_category( $category, $block, array $blocking, $show_fn ) {
		if ( empty( $block ) || 'necessary' === $category ) {
			return '';
		}
		if ( 'unclassified' === $category ) {
			return ! empty( $blocking['block_unclassified'] ) ? 'marketing' : '';
		}
		if ( 'functional' === $category ) {
			return $show_fn ? 'functional' : '';
		}
		return in_array( $category, array( 'analytics', 'marketing' ), true ) ? $category : '';
	}

	/**
	 * Empty snapshot.
	 *
	 * @return array
	 */
	private static function empty_snapshot() {
		return array(
			'v'       => self::VERSION,
			'rules'   => array(),
			'cookies' => array(),
			'capped'  => false,
		);
	}

	/**
	 * Read the catalog.
	 *
	 * @return array|null Null while the catalog tables do not exist yet.
	 */
	private static function build() {
		global $wpdb;
		if ( get_option( 'dscc_db_version' ) !== Schema::VERSION ) {
			return null;
		}
		$services = Schema::services_table();
		$cookies  = Schema::cookies_table();
		$out      = self::empty_snapshot();

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT slug, category_key, block_enabled, script_patterns FROM {$services} WHERE status IN ('confirmed','auto') AND category_key <> 'necessary' AND (block_enabled = 1 OR slug = %s) ORDER BY id ASC", self::GTM_SLUG ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$patterns = PatternMatcher::normalize_patterns( json_decode( (string) $row['script_patterns'], true ) );
			$hosts    = array_values( array_unique( array_filter( array_map( 'strtolower', array_map( 'trim', $patterns['hosts'] ) ) ) ) );
			$src      = array_values( array_filter( $patterns['src_regex'], array( PatternMatcher::class, 'valid_regex' ) ) );
			$inline   = array_values( array_filter( $patterns['inline_regex'], array( PatternMatcher::class, 'valid_regex' ) ) );
			if ( ! $hosts && ! $src && ! $inline ) {
				continue;
			}
			$out['rules'][] = array(
				's' => $row['slug'],
				'c' => $row['category_key'],
				'b' => (int) $row['block_enabled'],
				'h' => $hosts,
				'r' => $src,
				'i' => $inline,
			);
		}

		$base = "SELECT c.name, c.storage, s.slug, s.category_key FROM {$cookies} c INNER JOIN {$services} s ON s.id = c.service_id WHERE s.status IN ('confirmed','auto') AND s.block_enabled = 1 AND s.category_key <> 'necessary'";
		$list = $wpdb->get_results( $base . " AND c.status IN ('confirmed','auto') ORDER BY c.id ASC LIMIT " . (int) self::MAX_COOKIES, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom tables, no user input.
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			$out['cookies'][] = array( $row['name'], $row['storage'], $row['slug'], $row['category_key'], 0 );
		}
		// New, unclassified cookies: only literal COOKIES the server itself sent (what a visitor's browser reports can be
		// forged), after the confirmed and automatic ones, and at most MAX_NEW_COOKIES of them.
		$room = max( 0, min( self::MAX_NEW_COOKIES, self::MAX_COOKIES - count( $out['cookies'] ) ) );
		$new  = $wpdb->get_results( $base . " AND c.status = 'new' AND c.storage = 'cookie' AND c.name_is_pattern = 0 AND c.set_by = 'server' AND s.category_key = 'unclassified' ORDER BY c.id ASC LIMIT " . ( self::MAX_NEW_COOKIES + 1 ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom tables, no user input.
		$new  = is_array( $new ) ? $new : array();
		if ( count( $new ) > $room ) {
			$out['capped'] = true;
			$new           = array_slice( $new, 0, $room );
		}
		foreach ( $new as $row ) {
			$out['cookies'][] = array( $row['name'], $row['storage'], $row['slug'], $row['category_key'], 1 );
		}
		return $out;
	}
}
