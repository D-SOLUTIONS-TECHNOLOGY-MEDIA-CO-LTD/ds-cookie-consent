<?php
/**
 * Resolves the visitor language (vi or en) for the server-rendered strings.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class LanguageResolver {

	/**
	 * Current language: WPML, Polylang, then the WordPress locale. Anything that is neither Vietnamese nor
	 * English maps to the configured default language (advanced.default_lang, Vietnamese unless changed).
	 *
	 * @return string vi|en
	 */
	public static function current() {
		$code = '';
		$wpml = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party WPML hook.
		if ( is_string( $wpml ) && '' !== $wpml ) {
			$code = $wpml;
		} elseif ( function_exists( 'pll_current_language' ) ) {
			$pll = pll_current_language();
			if ( is_string( $pll ) && '' !== $pll ) {
				$code = $pll;
			}
		}
		if ( '' === $code ) {
			$code = determine_locale();
		}
		return self::map( $code, self::default_language() );
	}

	/**
	 * Language used for locales that are neither vi nor en.
	 *
	 * @return string vi|en
	 */
	public static function default_language() {
		$lang = (string) apply_filters( 'dscc_default_language', (string) Settings::get( 'advanced', 'default_lang', 'vi' ) );
		return 'en' === $lang ? 'en' : 'vi';
	}

	/**
	 * True when both languages are printed and the browser picks one from <html lang>: chosen in the
	 * settings, or automatic when GTranslate is active (it rewrites <html lang> on the client).
	 *
	 * @return bool
	 */
	public static function is_client_side() {
		$client = 'client' === Settings::get( 'advanced', 'lang_source', 'server' ) || class_exists( 'GTranslate', false );
		return (bool) apply_filters( 'dscc_client_side_language', $client );
	}

	/**
	 * Map any locale or language code to a supported language.
	 *
	 * @param string $code    Locale such as vi, vi_VN, en_US.
	 * @param string $fallback Language for unknown codes ('en' or anything else = Vietnamese; '' = Vietnamese).
	 * @return string vi|en
	 */
	public static function map( $code, $fallback = 'vi' ) {
		$code = strtolower( substr( (string) $code, 0, 2 ) );
		if ( 'vi' === $code ) {
			return 'vi';
		}
		if ( 'en' === $code ) {
			return 'en';
		}
		return 'en' === $fallback ? 'en' : 'vi';
	}
}
