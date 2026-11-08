<?php
/**
 * Wrapper around the prefixed enshrined/svg-sanitize library. Fails closed: when the library
 * is not loadable, SVG is rejected instead of being passed through.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

final class SvgSanitizer {

	const LIBRARY_CLASS = 'DSolutions\\CookieConsent\\Vendor\\enshrined\\svgSanitize\\Sanitizer';

	/**
	 * Whether the sanitising library is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( self::LIBRARY_CLASS );
	}

	/**
	 * Sanitise SVG markup. Returns false when it cannot be made safe (or the library is missing).
	 *
	 * @param string $svg Raw SVG.
	 * @return string|false
	 */
	public static function sanitize( $svg ) {
		if ( ! self::is_available() || ! is_string( $svg ) || '' === trim( $svg ) ) {
			return false;
		}
		try {
			$class     = self::LIBRARY_CLASS;
			$sanitizer = new $class();
			if ( method_exists( $sanitizer, 'minify' ) ) {
				$sanitizer->minify( true );
			}
			// A logo has no reason to pull in remote files (tracking pixels, external <use> targets).
			if ( method_exists( $sanitizer, 'removeRemoteReferences' ) ) {
				$sanitizer->removeRemoteReferences( true );
			}
			$clean = $sanitizer->sanitize( $svg );
		} catch ( \Throwable $e ) {
			return false;
		}
		if ( ! is_string( $clean ) || '' === trim( $clean ) || false === stripos( $clean, '<svg' ) ) {
			return false;
		}
		return $clean;
	}
}
