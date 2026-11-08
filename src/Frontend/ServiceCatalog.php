<?php
/**
 * Services from the catalog (filter dscc_catalog_services, fed by Catalog\FrontendCatalogCache) and the category list built from them.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

defined( 'ABSPATH' ) || exit;

final class ServiceCatalog {

	/** Same limits the log endpoint enforces on the "s" map. */
	const MAX_SERVICES    = 60;
	const MAX_SLUG_LENGTH = 40;

	/**
	 * Confirmed services. Expected item: slug, name, category, privacy_url, blockable (optional, default true: the
	 * service gets its own switch), cookies[ {name, duration, purpose, domain} ];
	 * name/duration/purpose are a string or a {vi, en} pair. Items without a valid slug (a-z, 0-9, _ and -, up to 40
	 * characters) are skipped and at most $limit are kept (60 by default, the cap of the log's "s" map), so a stored
	 * choice always passes the log validation.
	 *
	 * @param bool $show_functional Whether the functional category is visible.
	 * @param int  $limit           Maximum services (0 = unlimited, for shortcodes that only read).
	 * @return array[]
	 */
	public static function services( $show_functional, $limit = self::MAX_SERVICES ) {
		$items = apply_filters( 'dscc_catalog_services', array() );
		$out   = array();
		$seen  = array();
		foreach ( (array) $items as $item ) {
			if ( $limit > 0 && count( $out ) >= $limit ) {
				break;
			}
			if ( ! is_array( $item ) || empty( $item['slug'] ) || empty( $item['name'] ) || empty( $item['category'] ) ) {
				continue;
			}
			$slug = sanitize_key( (string) $item['slug'] );
			if ( '' === $slug || strlen( $slug ) > self::MAX_SLUG_LENGTH || isset( $seen[ $slug ] ) ) {
				continue;
			}
			$category = (string) $item['category'];
			if ( 'functional' === $category && ! $show_functional ) {
				$category = 'necessary';
			}
			if ( ! in_array( $category, array( 'necessary', 'functional', 'analytics', 'marketing' ), true ) ) {
				continue; // Unclassified services are never shown to visitors.
			}
			$seen[ $slug ] = true;
			$out[]         = array(
				'slug'        => $slug,
				'name'        => (string) $item['name'],
				'category'    => $category,
				'privacy_url' => isset( $item['privacy_url'] ) ? (string) $item['privacy_url'] : '',
				// Items from other sources that do not say anything keep their switch.
				'blockable'   => ! isset( $item['blockable'] ) || ! empty( $item['blockable'] ),
				'cookies'     => self::cookies( $item ),
			);
		}
		return $out;
	}

	/**
	 * Cookie rows of one catalog item: name, duration and purpose as {vi, en} pairs, the provider (the service
	 * name) and its privacy URL.
	 *
	 * @param array $item Catalog item.
	 * @return array[]
	 */
	private static function cookies( array $item ) {
		$out = array();
		foreach ( isset( $item['cookies'] ) ? (array) $item['cookies'] : array() as $cookie ) {
			if ( ! is_array( $cookie ) || empty( $cookie['name'] ) ) {
				continue;
			}
			$out[] = array(
				'id'          => isset( $cookie['id'] ) ? (int) $cookie['id'] : 0,
				'name'        => self::pair( $cookie['name'] ),
				'provider'    => isset( $cookie['provider'] ) ? (string) $cookie['provider'] : (string) $item['name'],
				'privacy_url' => isset( $item['privacy_url'] ) ? (string) $item['privacy_url'] : '',
				'duration'    => self::pair( isset( $cookie['duration'] ) ? $cookie['duration'] : '' ),
				'purpose'     => self::pair( isset( $cookie['purpose'] ) ? $cookie['purpose'] : '' ),
				'domain'      => isset( $cookie['domain'] ) ? (string) $cookie['domain'] : '',
				'group'       => isset( $cookie['group'] ) ? (string) $cookie['group'] : '',
				'applies'     => self::pair( isset( $cookie['applies'] ) ? $cookie['applies'] : '' ),
			);
		}
		return $out;
	}

	/**
	 * A {vi, en} text pair from a string (same text for both) or an array with either key.
	 *
	 * @param mixed $value String or array.
	 * @return array{vi:string,en:string}
	 */
	private static function pair( $value ) {
		if ( is_array( $value ) ) {
			$vi = isset( $value['vi'] ) ? (string) $value['vi'] : '';
			$en = isset( $value['en'] ) ? (string) $value['en'] : '';
			return array(
				'vi' => '' !== $vi ? $vi : $en,
				'en' => '' !== $en ? $en : $vi,
			);
		}
		return array(
			'vi' => (string) $value,
			'en' => (string) $value,
		);
	}

	/**
	 * Category list for the templates (names and descriptions are text keys, resolved by Text).
	 *
	 * @param array $services        Normalised services.
	 * @param bool  $show_functional Show the functional category.
	 * @return array[]
	 */
	public static function categories( array $services, $show_functional ) {
		$keys = $show_functional ? array( 'necessary', 'functional', 'analytics', 'marketing' ) : array( 'necessary', 'analytics', 'marketing' );
		$out  = array();
		foreach ( $keys as $key ) {
			$out[] = array(
				'key'      => $key,
				'required' => 'necessary' === $key,
				'services' => array_values(
					array_filter(
						$services,
						static function ( $s ) use ( $key ) {
							return $s['category'] === $key;
						}
					)
				),
			);
		}
		return $out;
	}
}
