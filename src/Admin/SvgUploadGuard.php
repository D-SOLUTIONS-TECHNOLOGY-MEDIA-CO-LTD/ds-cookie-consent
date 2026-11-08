<?php
/**
 * Lets administrators upload SVG logos, always sanitised. When the sanitising library cannot be
 * loaded the SVG mime type is not offered at all (fail closed).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Settings\SvgSanitizer;

defined( 'ABSPATH' ) || exit;

final class SvgUploadGuard {

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_filter( 'upload_mimes', array( __CLASS__, 'allow_svg_mime' ) );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'fix_filetype' ), 10, 3 );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'sanitize_upload' ) );
	}

	/**
	 * Allow SVG only for administrators and only when the sanitiser is available.
	 *
	 * @param array $mimes Allowed mime types.
	 * @return array
	 */
	public static function allow_svg_mime( $mimes ) {
		if ( current_user_can( 'manage_options' ) && SvgSanitizer::is_available() ) {
			$mimes['svg'] = 'image/svg+xml';
		}
		return $mimes;
	}

	/**
	 * WordPress cannot always sniff SVG; trust the extension for permitted users.
	 *
	 * @param array  $data     Filetype data.
	 * @param string $file     File path.
	 * @param string $filename File name.
	 * @return array
	 */
	public static function fix_filetype( $data, $file, $filename ) {
		if ( empty( $data['type'] ) && preg_match( '/\.svg$/i', (string) $filename ) && current_user_can( 'manage_options' ) && SvgSanitizer::is_available() ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

	/**
	 * Replace the uploaded temp file with its sanitised version, or reject it.
	 *
	 * @param array $file Upload data ($_FILES entry).
	 * @return array
	 */
	public static function sanitize_upload( $file ) {
		$is_svg = ( isset( $file['type'] ) && 'image/svg+xml' === $file['type'] ) || ( isset( $file['name'] ) && preg_match( '/\.svg$/i', (string) $file['name'] ) );
		if ( ! $is_svg ) {
			return $file;
		}
		if ( ! current_user_can( 'manage_options' ) || ! SvgSanitizer::is_available() ) {
			$file['error'] = __( 'Cannot upload SVG: the SVG sanitising filter is not available.', 'ds-cookie-consent' );
			return $file;
		}
		$raw = isset( $file['tmp_name'] ) && is_readable( $file['tmp_name'] ) ? file_get_contents( $file['tmp_name'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp upload.
		if ( false === $raw ) {
			$file['error'] = __( 'Could not read the SVG file.', 'ds-cookie-consent' );
			return $file;
		}
		$clean = SvgSanitizer::sanitize( $raw );
		if ( false === $clean || false === file_put_contents( $file['tmp_name'], $clean ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- rewrite of the temp upload.
			$file['error'] = __( 'The SVG file is unsafe or invalid.', 'ds-cookie-consent' );
		}
		return $file;
	}
}
