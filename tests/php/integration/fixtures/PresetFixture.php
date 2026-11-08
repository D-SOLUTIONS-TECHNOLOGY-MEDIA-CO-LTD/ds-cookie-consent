<?php
/**
 * Test helper: loads an export-format preset (presets/d-solutions-standard.json) straight into the catalog tables.
 * The real importer arrives with the import/export milestone; this only mirrors the preset format for tests.
 */

use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\ServiceRepository;

class Dscc_Preset_Fixture {

	/**
	 * Load a preset file.
	 *
	 * @param string $path Preset file; defaults to presets/d-solutions-standard.json.
	 * @return array{services:int, cookies:int}
	 */
	public static function load( $path = '' ) {
		$path   = '' === $path ? dirname( __DIR__, 4 ) . '/presets/d-solutions-standard.json' : $path;
		$preset = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $preset ) || 'dscc-export' !== $preset['format'] ) {
			throw new RuntimeException( 'Not a dscc-export preset: ' . $path );
		}
		$ids = array();
		foreach ( $preset['services'] as $service ) {
			$ids[ $service['slug'] ] = ServiceRepository::upsert( $service );
		}
		$cookies = 0;
		foreach ( $preset['cookies'] as $cookie ) {
			$cookie['service_id'] = isset( $ids[ $cookie['service_slug'] ] ) ? $ids[ $cookie['service_slug'] ] : 0;
			unset( $cookie['service_slug'] );
			if ( CookieRepository::upsert( $cookie ) ) {
				++$cookies;
			}
		}
		return array(
			'services' => count( $ids ),
			'cookies'  => $cookies,
		);
	}
}
