<?php
/**
 * CSS custom properties derived from the appearance settings. Colours are re-validated here so a
 * hand-edited option can never put arbitrary text into the style attribute.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\ColorUtils;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class CssVars {

	/**
	 * Inline style attribute value with all custom properties.
	 *
	 * @param array  $a      Appearance settings.
	 * @param string $layout card|bar.
	 * @return string
	 */
	public static function style( array $a, $layout ) {
		$out = '';
		foreach ( self::map( $a, $layout ) as $name => $value ) {
			$out .= $name . ':' . $value . ';';
		}
		return $out;
	}

	/**
	 * Custom property map (also used by the live preview).
	 *
	 * @param array  $a      Appearance settings.
	 * @param string $layout card|bar.
	 * @return array<string,string>
	 */
	public static function map( array $a, $layout ) {
		$defaults = SettingsSchema::defaults()['appearance'];
		$primary  = self::color( $a, 'color_primary', $defaults['color_primary'] );
		$hover    = ColorUtils::normalize_hex( isset( $a['color_primary_hover'] ) ? $a['color_primary_hover'] : '' );
		$reject   = ColorUtils::normalize_hex( isset( $a['color_reject_bg'] ) ? $a['color_reject_bg'] : '' );

		$radius = (string) $a['button_radius'];
		if ( '' === $radius ) {
			$radius = 'bar' === $layout ? 'pill' : '8';
		}
		$radius_css = 'pill' === $radius ? '999px' : max( 0, min( 40, (int) $radius ) ) . 'px';

		$font = (string) $a['font'];
		$font = '' === $font || ! preg_match( '/^[A-Za-z0-9 ,\-_]+$/', $font ) ? 'inherit' : $font;

		return array(
			'--dscc-primary'       => $primary,
			'--dscc-primary-hover' => $hover ? $hover : ColorUtils::darken( $primary, 0.12 ),
			'--dscc-text'          => self::color( $a, 'color_text', $defaults['color_text'] ),
			'--dscc-bar-bg'        => self::color( $a, 'color_bar_bg', $defaults['color_bar_bg'] ),
			'--dscc-reject-bg'     => $reject ? $reject : ColorUtils::tint_on_white( $primary, 0.12 ),
			'--dscc-modal-bg'      => self::color( $a, 'color_modal_bg', $defaults['color_modal_bg'] ),
			'--dscc-radius'        => $radius_css,
			'--dscc-overlay'       => 'rgba(15,23,42,' . round( max( 0, min( 60, (int) $a['overlay_opacity'] ) ) / 100, 2 ) . ')',
			'--dscc-widget-x'      => (int) $a['widget_offset_x'] . 'px',
			'--dscc-widget-bottom' => (int) $a['widget_bottom'] . 'px',
			'--dscc-font'          => $font,
			'--dscc-logo-h'        => (int) $a['logo_height'] . 'px',
			'--dscc-logo-popup-h'  => (int) $a['logo_popup_height'] . 'px',
		);
	}

	/**
	 * A validated hex colour from the settings, or the default.
	 *
	 * @param array  $a       Appearance settings.
	 * @param string $key     Setting key.
	 * @param string $fallback Fallback hex.
	 * @return string
	 */
	private static function color( array $a, $key, $fallback ) {
		$hex = ColorUtils::normalize_hex( isset( $a[ $key ] ) ? $a[ $key ] : '' );
		return $hex ? $hex : $fallback;
	}
}
