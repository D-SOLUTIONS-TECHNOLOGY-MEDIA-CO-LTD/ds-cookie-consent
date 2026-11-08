<?php
/**
 * Shape of a cookies table row: defaults for a new row, input validation, and typed hydration of a stored row.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

defined( 'ABSPATH' ) || exit;

final class CookieRow {

	/**
	 * Default column values for a new row.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'service_id'        => 0,
			'name'              => '',
			'name_is_pattern'   => 0,
			'storage'           => 'cookie',
			'domain'            => '',
			'path'              => '/',
			'duration_label'    => '',
			'duration_label_en' => '',
			'duration_seconds'  => null,
			'purpose'           => '',
			'purpose_en'        => '',
			'http_only'         => 0,
			'set_by'            => 'unknown',
			'table_group'       => '',
			'applies_to'        => '',
			'applies_to_en'     => '',
			'status'            => 'new',
			'source'            => 'manual',
		);
	}

	/**
	 * Validate and normalise the writable fields present in $data (name is always returned).
	 *
	 * @param array $data Input.
	 * @return array
	 */
	public static function sanitize( array $data ) {
		$name = isset( $data['name'] ) ? mb_substr( trim( sanitize_text_field( (string) $data['name'] ) ), 0, 100 ) : '';
		$out  = array(
			'name'            => $name,
			'name_is_pattern' => PatternMatcher::is_pattern( $name ) ? 1 : 0,
		);
		if ( isset( $data['service_id'] ) ) {
			$out['service_id'] = absint( $data['service_id'] );
		}
		if ( isset( $data['storage'] ) ) {
			$out['storage'] = in_array( $data['storage'], CookieRepository::STORAGES, true ) ? $data['storage'] : 'cookie';
		}
		if ( isset( $data['domain'] ) ) {
			$out['domain'] = strtolower( mb_substr( preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) $data['domain'] ), 0, 64 ) );
		}
		if ( isset( $data['path'] ) ) {
			$out['path'] = mb_substr( sanitize_text_field( (string) $data['path'] ), 0, 100 );
		}
		foreach ( array(
			'duration_label'    => 120,
			'duration_label_en' => 120,
		) as $key => $max ) {
			if ( isset( $data[ $key ] ) ) {
				$out[ $key ] = mb_substr( trim( sanitize_text_field( (string) $data[ $key ] ) ), 0, $max );
			}
		}
		foreach ( array( 'purpose', 'purpose_en' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$out[ $key ] = mb_substr( trim( sanitize_textarea_field( (string) $data[ $key ] ) ), 0, 1000 );
			}
		}
		if ( array_key_exists( 'duration_seconds', $data ) ) {
			$out['duration_seconds'] = null === $data['duration_seconds'] || '' === $data['duration_seconds'] ? null : absint( $data['duration_seconds'] );
		}
		if ( isset( $data['table_group'] ) ) {
			$out['table_group'] = substr( sanitize_key( (string) $data['table_group'] ), 0, 60 );
		}
		foreach ( array( 'applies_to', 'applies_to_en' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$out[ $key ] = mb_substr( trim( sanitize_text_field( (string) $data[ $key ] ) ), 0, 255 );
			}
		}
		if ( isset( $data['http_only'] ) ) {
			$out['http_only'] = empty( $data['http_only'] ) ? 0 : 1;
		}
		if ( isset( $data['set_by'] ) ) {
			$out['set_by'] = in_array( $data['set_by'], CookieRepository::SET_BY, true ) ? $data['set_by'] : 'unknown';
		}
		if ( isset( $data['status'] ) ) {
			$out['status'] = in_array( $data['status'], CookieRepository::STATUSES, true ) ? $data['status'] : 'new';
		}
		if ( isset( $data['source'] ) ) {
			$out['source'] = in_array( $data['source'], CookieRepository::SOURCES, true ) ? $data['source'] : 'manual';
		}
		return $out;
	}

	/**
	 * Cast a database row to typed values.
	 *
	 * @param array $row Raw row.
	 * @return array
	 */
	public static function hydrate( array $row ) {
		foreach ( array( 'id', 'service_id', 'name_is_pattern', 'http_only', 'seen_count' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$row[ $key ] = (int) $row[ $key ];
			}
		}
		$row['duration_seconds'] = isset( $row['duration_seconds'] ) && null !== $row['duration_seconds'] ? (int) $row['duration_seconds'] : null;
		return $row;
	}
}
