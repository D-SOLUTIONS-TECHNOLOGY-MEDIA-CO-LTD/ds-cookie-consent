<?php
/**
 * Placeholder printed in front of a blocked iframe: a short message and a button that asks for the category.
 * The markup is the same for every visitor (page-cache safe); the front-end script removes it on consent.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

use DSolutions\CookieConsent\Frontend\LanguageResolver;
use DSolutions\CookieConsent\Frontend\Text;
use DSolutions\CookieConsent\Settings\DefaultTexts;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Placeholder {

	/** Services whose embeds are videos: they get the "to watch this video" wording. */
	const VIDEO_SERVICES = array( 'youtube', 'vimeo' );

	/**
	 * Text view model used by the Text helper (both languages when the page language is picked in the browser).
	 *
	 * @var array|null
	 */
	private static $vm = null;

	/**
	 * Build the placeholder HTML.
	 *
	 * @param array $info service, category (may be "analytics marketing"), src, width, height.
	 * @return string Safe HTML.
	 */
	public static function html( array $info ) {
		$vm       = self::vm();
		$category = preg_split( '/\s+/', trim( (string) $info['category'] ) );
		$category = in_array( 'marketing', $category, true ) || count( $category ) > 1 ? 'marketing' : $category[0];
		$name     = self::category_name( $vm, $category );
		$key      = in_array( $info['service'], self::VIDEO_SERVICES, true ) || preg_match( '~youtu|vimeo~i', (string) $info['src'] ) ? 'ph_video' : 'ph_text';
		$style    = self::style( $info );

		return '<div class="dscc-placeholder"' . $style . ' data-dscc-ph="' . esc_attr( $info['service'] ) . '">'
			. '<p class="dscc-placeholder__text">' . self::text( $vm, $key, $name ) . '</p>'
			. '<button type="button" class="dscc-placeholder__btn" data-dscc-enable="' . esc_attr( $info['service'] ) . '" data-dscc-enable-cat="' . esc_attr( $category ) . '">' . self::text( $vm, 'ph_button', $name ) . '</button>'
			. '</div>';
	}

	/**
	 * Size and colours as inline custom properties (the placeholder sits outside the #dscc root).
	 *
	 * @param array $info Placeholder info.
	 * @return string Attribute with a leading space.
	 */
	private static function style( array $info ) {
		$vars = '--dscc-primary:' . self::color() . ';';
		foreach ( array(
			'width'  => '--dscc-ph-w',
			'height' => '--dscc-ph-h',
		) as $key => $var ) {
			if ( preg_match( '/^\d{2,4}$/', trim( (string) $info[ $key ] ) ) ) {
				$vars .= $var . ':' . (int) $info[ $key ] . 'px;';
			}
		}
		return ' style="' . esc_attr( $vars ) . '"';
	}

	/**
	 * Primary colour from the appearance settings (already validated as a hex colour).
	 *
	 * @return string
	 */
	private static function color() {
		$color = (string) Settings::get( 'appearance', 'color_primary', '#1e3a8a' );
		return preg_match( '/^#[0-9a-f]{3,8}$/i', $color ) ? $color : '#1e3a8a';
	}

	/**
	 * Category noun in both languages (dual mode) or the page language.
	 *
	 * @param array  $vm       Text view model.
	 * @param string $category Category key.
	 * @return array<string,string> lang => noun.
	 */
	private static function category_name( array $vm, $category ) {
		$out = array();
		foreach ( empty( $vm['dual'] ) ? array( $vm['lang'] ) : DefaultTexts::LANGS as $lang ) {
			$out[ $lang ] = Text::raw( $vm, $lang, 'ph_cat_' . $category );
		}
		return $out;
	}

	/**
	 * One text with the category noun, as safe HTML.
	 *
	 * @param array  $vm   Text view model.
	 * @param string $key  Text key.
	 * @param array  $name Category noun per language.
	 * @return string
	 */
	private static function text( array $vm, $key, array $name ) {
		if ( empty( $vm['dual'] ) ) {
			return esc_html( Text::raw( $vm, $vm['lang'], $key, array( '{category}' => $name[ $vm['lang'] ] ) ) );
		}
		$out = '';
		foreach ( DefaultTexts::LANGS as $lang ) {
			$out .= '<span data-dscc-lang="' . esc_attr( $lang ) . '">' . esc_html( Text::raw( $vm, $lang, $key, array( '{category}' => $name[ $lang ] ) ) ) . '</span>';
		}
		return $out;
	}

	/**
	 * Minimal view model for the Text helper.
	 *
	 * @return array
	 */
	private static function vm() {
		if ( null !== self::$vm ) {
			return self::$vm;
		}
		$lang = LanguageResolver::current();
		$dual = LanguageResolver::is_client_side();
		$by   = array();
		foreach ( $dual ? DefaultTexts::LANGS : array( $lang ) as $code ) {
			$by[ $code ] = Settings::texts( $code );
		}
		self::$vm = array(
			'lang'          => $lang,
			'dual'          => $dual,
			'texts'         => $by[ $lang ],
			'texts_by_lang' => $by,
		);
		return self::$vm;
	}

	/**
	 * Forget the cached text model (tests).
	 */
	public static function flush() {
		self::$vm = null;
	}
}
