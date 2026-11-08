<?php
/**
 * WCAG 2.1 AA contrast checks for the configured colours (warnings only, saving is never blocked).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

final class ContrastChecker {

	const MIN_RATIO = 4.5;

	/**
	 * Failed checks as code => ratio.
	 *
	 * @param array $appearance Appearance settings.
	 * @return array<string,float>
	 */
	public static function failures( array $appearance ) {
		$out    = array();
		$checks = array(
			'primary_on_white' => array( '#ffffff', isset( $appearance['color_primary'] ) ? $appearance['color_primary'] : '' ),
			'text_on_bar'      => array( isset( $appearance['color_text'] ) ? $appearance['color_text'] : '', isset( $appearance['color_bar_bg'] ) ? $appearance['color_bar_bg'] : '' ),
		);
		foreach ( $checks as $code => $pair ) {
			if ( null === ColorUtils::normalize_hex( $pair[0] ) || null === ColorUtils::normalize_hex( $pair[1] ) ) {
				continue;
			}
			$ratio = ColorUtils::contrast( $pair[0], $pair[1] );
			if ( $ratio < self::MIN_RATIO ) {
				$out[ $code ] = round( $ratio, 2 );
			}
		}
		return $out;
	}
}
