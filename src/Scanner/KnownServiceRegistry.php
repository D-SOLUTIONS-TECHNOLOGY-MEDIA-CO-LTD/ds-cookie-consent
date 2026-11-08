<?php
/**
 * The site's rows for known platforms and the hidden first-party holders of cookies without a vendor.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Catalog\ServiceRepository;

defined( 'ABSPATH' ) || exit;

final class KnownServiceRegistry {

	/**
	 * Name stored for a platform the scan could not identify. It is data in the catalog (like the Vietnamese duration
	 * labels), so it is a fixed text and never depends on the language of the request that created the row.
	 */
	const UNDETERMINED_NAME = 'Chưa xác định';

	/**
	 * The site's row for a known platform, created (status auto) when missing; `detected_via` gains the new source.
	 *
	 * @param array  $def Known-database service entry.
	 * @param string $via    Detection source.
	 * @param bool   $create Create the row when missing (false for visitor-reported data).
	 * @return array|null Service row.
	 */
	public static function ensure( array $def, $via, $create = true ) {
		$row = ServiceRepository::find_by_slug( $def['slug'] );
		if ( ! $row && ! $create ) {
			return null;
		}
		if ( ! $row ) {
			$id = ServiceRepository::upsert(
				array(
					'slug'             => $def['slug'],
					'name'             => $def['name'],
					'category_key'     => $def['category'],
					'provider_company' => $def['provider_company'],
					'privacy_url'      => $def['privacy_url'],
					'is_third_party'   => $def['is_third_party'],
					'country'          => $def['country'],
					'script_patterns'  => $def['script_patterns'],
					'status'           => 'auto',
					'source'           => 'known_db',
					'detected_via'     => array( $via ),
				)
			);
			return $id ? ServiceRepository::find( $id ) : null;
		}
		if ( 'ignored' !== $row['status'] && ! in_array( $via, $row['detected_via'], true ) ) {
			ServiceRepository::upsert(
				array(
					'slug'         => $row['slug'],
					'detected_via' => array_merge( $row['detected_via'], array( $via ) ),
				)
			);
			$row = ServiceRepository::find( $row['id'] );
		}
		return $row;
	}

	/**
	 * First-party service that holds cookies without a vendor, created hidden (status auto) when missing.
	 *
	 * @param string $category necessary|unclassified.
	 * @return int Service id.
	 */
	public static function holder( $category ) {
		$slug = 'site-' . $category;
		$row  = ServiceRepository::find_by_slug( $slug );
		if ( $row ) {
			return (int) $row['id'];
		}
		return ServiceRepository::upsert(
			array(
				'slug'           => $slug,
				'name'           => 'necessary' === $category ? wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) : self::UNDETERMINED_NAME,
				'category_key'   => $category,
				'is_third_party' => 0,
				'status'         => 'auto',
				'source'         => 'manual',
			)
		);
	}
}
