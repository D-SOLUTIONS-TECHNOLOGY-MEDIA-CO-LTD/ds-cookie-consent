<?php
/**
 * Pure colour helpers: hex parsing, WCAG contrast, derived hover / reject colours.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

final class ColorUtils {

	/**
	 * Normalise #rgb / #rrggbb to lowercase #rrggbb. Returns null when invalid.
	 *
	 * @param mixed $value Raw colour value.
	 * @return string|null
	 */
	public static function normalize_hex( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = strtolower( trim( $value ) );
		if ( preg_match( '/^#([0-9a-f]{3})$/', $value, $m ) ) {
			$c = $m[1];
			return '#' . $c[0] . $c[0] . $c[1] . $c[1] . $c[2] . $c[2];
		}
		if ( preg_match( '/^#[0-9a-f]{6}$/', $value ) ) {
			return $value;
		}
		return null;
	}

	/**
	 * Hex to [r, g, b] ints. Returns null when invalid.
	 *
	 * @param string $hex Colour.
	 * @return int[]|null
	 */
	public static function to_rgb( $hex ) {
		$hex = self::normalize_hex( $hex );
		if ( null === $hex ) {
			return null;
		}
		return array(
			hexdec( substr( $hex, 1, 2 ) ),
			hexdec( substr( $hex, 3, 2 ) ),
			hexdec( substr( $hex, 5, 2 ) ),
		);
	}

	/**
	 * [r, g, b] to #rrggbb with channel clamping.
	 *
	 * @param float[]|int[] $rgb Channels.
	 * @return string
	 */
	public static function to_hex( array $rgb ) {
		$out = '#';
		foreach ( $rgb as $channel ) {
			$out .= str_pad( dechex( max( 0, min( 255, (int) round( $channel ) ) ) ), 2, '0', STR_PAD_LEFT );
		}
		return $out;
	}

	/**
	 * WCAG relative luminance.
	 *
	 * @param string $hex Colour.
	 * @return float
	 */
	public static function luminance( $hex ) {
		$rgb = self::to_rgb( $hex );
		if ( null === $rgb ) {
			return 0.0;
		}
		$lin = array();
		foreach ( $rgb as $c ) {
			$c     = $c / 255;
			$lin[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}
		return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
	}

	/**
	 * WCAG contrast ratio between two colours (1.0 to 21.0).
	 *
	 * @param string $a First colour.
	 * @param string $b Second colour.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		$hi = max( $la, $lb );
		$lo = min( $la, $lb );
		return ( $hi + 0.05 ) / ( $lo + 0.05 );
	}

	/**
	 * Darken by mixing with black (every channel scaled by 1 - amount). Default 12%.
	 *
	 * @param string $hex    Colour.
	 * @param float  $amount 0..1.
	 * @return string
	 */
	public static function darken( $hex, $amount = 0.12 ) {
		$rgb = self::to_rgb( $hex );
		if ( null === $rgb ) {
			return '#000000';
		}
		$f = 1 - $amount;
		return self::to_hex( array( $rgb[0] * $f, $rgb[1] * $f, $rgb[2] * $f ) );
	}

	/**
	 * Light tint: $amount of the colour on top of white. Default 12% (used for the reject button background).
	 *
	 * @param string $hex    Colour.
	 * @param float  $amount 0..1 share of the colour.
	 * @return string
	 */
	public static function tint_on_white( $hex, $amount = 0.12 ) {
		$rgb = self::to_rgb( $hex );
		if ( null === $rgb ) {
			return '#ffffff';
		}
		$out = array();
		foreach ( $rgb as $c ) {
			$out[] = $c * $amount + 255 * ( 1 - $amount );
		}
		return self::to_hex( $out );
	}
}
