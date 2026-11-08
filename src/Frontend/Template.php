<?php
/**
 * Template loader with theme override support (ds-cookie-consent/<file> in the active theme).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

defined( 'ABSPATH' ) || exit;

final class Template {

	/**
	 * Resolve a template path: theme override first, then the plugin file.
	 *
	 * @param string $file Relative file such as bar.php or partials/modal.php.
	 * @return string Absolute path or empty string when missing.
	 */
	public static function locate( $file ) {
		$file = ltrim( str_replace( array( '..', '\\' ), '', $file ), '/' );
		$path = locate_template( 'ds-cookie-consent/' . $file );
		if ( ! $path ) {
			$path = DSCC_DIR . 'templates/' . $file;
		}
		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Width and height attributes for a resolved logo (lets the browser reserve space; CSS keeps the display size).
	 *
	 * @param array $logo Resolved logo: url, w, h.
	 * @return string Safe attribute markup with a leading space, or ''.
	 */
	public static function image_size_attrs( array $logo ) {
		return $logo['w'] > 0 && $logo['h'] > 0 ? ' width="' . (int) $logo['w'] . '" height="' . (int) $logo['h'] . '"' : '';
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- $vm and $args are read by the included template.
	/**
	 * Include a template with $vm and $args in scope (used by the included file).
	 *
	 * @param string $file Relative template file.
	 * @param array  $vm   View model.
	 * @param array  $args Extra values for partials (variant, category, cookies).
	 */
	public static function render( $file, array $vm, array $args = array() ) {
		$path = self::locate( $file );
		if ( '' === $path ) {
			return;
		}
		include $path; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- path is resolved by locate().
	}
	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
}
