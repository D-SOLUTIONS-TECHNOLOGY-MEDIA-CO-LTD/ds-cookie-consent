<?php
/**
 * Visitor text output for the templates. In single-language mode it escapes the one resolved text.
 * In dual mode (client-side language pick, e.g. GTranslate) it prints a span per language
 * (data-dscc-lang) and the front-end script shows the one matching <html lang>; the markup stays
 * identical for every visitor so page caches keep working.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\DefaultTexts;

defined( 'ABSPATH' ) || exit;

final class Text {

	/**
	 * Raw text for one language with {token} replacements applied.
	 *
	 * @param array  $vm      View model.
	 * @param string $lang    vi|en.
	 * @param string $key     Text key.
	 * @param array  $replace Token => value.
	 * @return string
	 */
	public static function raw( array $vm, $lang, $key, array $replace = array() ) {
		$texts = isset( $vm['texts_by_lang'][ $lang ] ) ? $vm['texts_by_lang'][ $lang ] : $vm['texts'];
		$text  = isset( $texts[ $key ] ) ? $texts[ $key ] : '';
		return $replace ? strtr( $text, $replace ) : $text;
	}

	/**
	 * Escaped HTML for a text key (one string, or one span per language in dual mode).
	 *
	 * @param array  $vm      View model.
	 * @param string $key     Text key.
	 * @param array  $replace Token => value, for example array( '{n}' => '3' ).
	 * @return string Safe HTML.
	 */
	public static function html( array $vm, $key, array $replace = array() ) {
		if ( empty( $vm['dual'] ) ) {
			return esc_html( self::raw( $vm, $vm['lang'], $key, $replace ) );
		}
		$out = '';
		foreach ( DefaultTexts::LANGS as $lang ) {
			$out .= '<span data-dscc-lang="' . esc_attr( $lang ) . '">' . esc_html( self::raw( $vm, $lang, $key, $replace ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * Escaped HTML for a {vi, en} data pair (catalog texts). Dual mode prints a span per language unless both are equal.
	 *
	 * @param array $vm   View model.
	 * @param array $pair Array with vi and en strings.
	 * @return string Safe HTML.
	 */
	public static function value( array $vm, array $pair ) {
		$vi = isset( $pair['vi'] ) ? (string) $pair['vi'] : '';
		$en = isset( $pair['en'] ) ? (string) $pair['en'] : '';
		if ( empty( $vm['dual'] ) ) {
			return esc_html( 'en' === $vm['lang'] ? $en : $vi );
		}
		if ( $vi === $en ) {
			return esc_html( $vi );
		}
		return '<span data-dscc-lang="vi">' . esc_html( $vi ) . '</span><span data-dscc-lang="en">' . esc_html( $en ) . '</span>';
	}

	/**
	 * Attribute fragment such as ` aria-label="..."`. In dual mode the other languages ride along in data
	 * attributes and the front-end script swaps the attribute when the page language changes.
	 *
	 * @param array  $vm      View model.
	 * @param string $attr    Attribute name.
	 * @param string $key     Text key.
	 * @param array  $replace Token => value.
	 * @return string Safe attribute markup with a leading space.
	 */
	public static function attr( array $vm, $attr, $key, array $replace = array() ) {
		$out = ' ' . $attr . '="' . esc_attr( self::raw( $vm, $vm['lang'], $key, $replace ) ) . '"';
		if ( ! empty( $vm['dual'] ) ) {
			$out .= ' data-dscc-attr="' . esc_attr( $attr ) . '"';
			foreach ( DefaultTexts::LANGS as $lang ) {
				$out .= ' data-dscc-' . $lang . '="' . esc_attr( self::raw( $vm, $lang, $key, $replace ) ) . '"';
			}
		}
		return $out;
	}

	/**
	 * Message with the {policy_link} token swapped for the link markup (per language in dual mode).
	 *
	 * @param array  $vm  View model.
	 * @param string $key Text key.
	 * @return string Safe HTML.
	 */
	public static function message( array $vm, $key ) {
		$langs = empty( $vm['dual'] ) ? array( $vm['lang'] ) : DefaultTexts::LANGS;
		$out   = '';
		foreach ( $langs as $lang ) {
			$html = str_replace( '{policy_link}', PolicyLinks::link_html( $vm, $lang ), esc_html( self::raw( $vm, $lang, $key ) ) );
			$out .= empty( $vm['dual'] ) ? $html : '<span data-dscc-lang="' . esc_attr( $lang ) . '">' . $html . '</span>';
		}
		return $out;
	}
}
