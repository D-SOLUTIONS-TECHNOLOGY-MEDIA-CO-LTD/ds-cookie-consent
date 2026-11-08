<?php
/**
 * Shape of a services table row: defaults for a new row, input validation, and typed hydration of a stored row.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

defined( 'ABSPATH' ) || exit;

final class ServiceRow {

	/**
	 * Default column values for a new row.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'slug'             => '',
			'name'             => '',
			'category_key'     => 'unclassified',
			'provider_company' => '',
			'privacy_url'      => '',
			'is_third_party'   => 1,
			'transfers_abroad' => 0,
			'country'          => '',
			'script_patterns'  => wp_json_encode( PatternMatcher::normalize_patterns( array() ), JSON_UNESCAPED_SLASHES ),
			'block_enabled'    => 1,
			'status'           => 'auto',
			'source'           => 'manual',
			'detected_via'     => '[]',
		);
	}

	/**
	 * Validate and normalise the writable fields that are present in $data.
	 *
	 * @param array $data Input.
	 * @return array Clean values for the keys of $data (slug always present).
	 */
	public static function sanitize( array $data ) {
		$out = array( 'slug' => '' );
		if ( isset( $data['slug'] ) ) {
			$slug        = sanitize_key( (string) $data['slug'] );
			$out['slug'] = strlen( $slug ) <= ServiceRepository::MAX_SLUG ? $slug : '';
		}
		$text = array(
			'name'             => 120,
			'provider_company' => 160,
		);
		foreach ( $text as $key => $max ) {
			if ( isset( $data[ $key ] ) ) {
				$out[ $key ] = mb_substr( trim( sanitize_text_field( (string) $data[ $key ] ) ), 0, $max );
			}
		}
		if ( isset( $data['category_key'] ) ) {
			$out['category_key'] = in_array( $data['category_key'], ServiceRepository::CATEGORIES, true ) ? $data['category_key'] : 'unclassified';
		}
		if ( isset( $data['status'] ) ) {
			$out['status'] = in_array( $data['status'], ServiceRepository::STATUSES, true ) ? $data['status'] : 'auto';
		}
		if ( isset( $data['source'] ) ) {
			$out['source'] = in_array( $data['source'], ServiceRepository::SOURCES, true ) ? $data['source'] : 'manual';
		}
		if ( isset( $data['privacy_url'] ) ) {
			$url                = trim( (string) $data['privacy_url'] );
			$out['privacy_url'] = preg_match( '#^https?://#i', $url ) ? substr( esc_url_raw( $url ), 0, 255 ) : '';
		}
		if ( isset( $data['country'] ) ) {
			$out['country'] = preg_match( '/^[A-Za-z]{2}$/', (string) $data['country'] ) ? strtoupper( (string) $data['country'] ) : '';
		}
		foreach ( array( 'is_third_party', 'transfers_abroad', 'block_enabled' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$out[ $key ] = empty( $data[ $key ] ) ? 0 : 1;
			}
		}
		if ( isset( $data['script_patterns'] ) ) {
			$patterns               = is_string( $data['script_patterns'] ) ? json_decode( $data['script_patterns'], true ) : $data['script_patterns'];
			$out['script_patterns'] = wp_json_encode( PatternMatcher::normalize_patterns( $patterns ), JSON_UNESCAPED_SLASHES );
		}
		if ( isset( $data['detected_via'] ) ) {
			$via                 = is_string( $data['detected_via'] ) ? json_decode( $data['detected_via'], true ) : $data['detected_via'];
			$out['detected_via'] = wp_json_encode( is_array( $via ) ? array_values( array_filter( $via, 'is_string' ) ) : array() );
		}
		// Necessary services are never blocked, whatever was asked.
		$category = isset( $out['category_key'] ) ? $out['category_key'] : null;
		if ( 'necessary' === $category ) {
			$out['block_enabled'] = 0;
		}
		return $out;
	}

	/**
	 * Cast a database row to typed values with decoded JSON columns.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate( array $row ) {
		$row['id']               = (int) $row['id'];
		$row['is_third_party']   = (int) $row['is_third_party'];
		$row['transfers_abroad'] = (int) $row['transfers_abroad'];
		$row['block_enabled']    = (int) $row['block_enabled'];
		$row['script_patterns']  = PatternMatcher::normalize_patterns( json_decode( (string) $row['script_patterns'], true ) );
		$via                     = json_decode( (string) $row['detected_via'], true );
		$row['detected_via']     = is_array( $via ) ? array_values( $via ) : array();
		return $row;
	}
}
