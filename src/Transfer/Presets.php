<?php
/**
 * Bundled presets: export-format files in presets/. They are only ever applied by an explicit admin action on the
 * Import / Export tab; nothing loads them automatically.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Transfer;

defined( 'ABSPATH' ) || exit;

final class Presets {

	/**
	 * Directory that holds the preset files.
	 *
	 * @return string
	 */
	public static function dir() {
		return DSCC_DIR . 'presets/';
	}

	/**
	 * Available presets, by slug (file name without .json).
	 *
	 * @return array<string,array{label:string,description:string}>
	 */
	public static function all() {
		$out   = array();
		$files = glob( self::dir() . '*.json' );
		foreach ( is_array( $files ) ? $files : array() as $file ) {
			$slug = basename( $file, '.json' );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ) {
				continue;
			}
			$doc          = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside the plugin.
			$meta         = is_array( $doc ) && isset( $doc['preset'] ) && is_array( $doc['preset'] ) ? $doc['preset'] : array();
			$label        = self::localized( $meta, 'label' );
			$out[ $slug ] = array(
				'label'       => '' !== $label ? $label : $slug,
				'description' => self::localized( $meta, 'description' ),
			);
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Text of a preset meta field in the admin language: the "<key>_en" variant outside Vietnamese locales when the
	 * file has one, otherwise the plain (Vietnamese) key.
	 *
	 * @param array  $meta Preset meta section of a file.
	 * @param string $key  label|description.
	 * @return string
	 */
	public static function localized( array $meta, $key ) {
		$english = $key . '_en';
		if ( 0 !== strpos( (string) determine_locale(), 'vi' ) && isset( $meta[ $english ] ) && is_string( $meta[ $english ] ) && '' !== $meta[ $english ] ) {
			return $meta[ $english ];
		}
		return isset( $meta[ $key ] ) && is_string( $meta[ $key ] ) ? $meta[ $key ] : '';
	}

	/**
	 * Raw JSON of one preset. Only slugs that exist in the presets folder are accepted.
	 *
	 * @param string $slug Preset slug.
	 * @return string|null
	 */
	public static function json( $slug ) {
		if ( ! is_string( $slug ) || ! isset( self::all()[ $slug ] ) ) {
			return null;
		}
		$json = file_get_contents( self::dir() . $slug . '.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside the plugin.
		return false === $json ? null : $json;
	}
}
