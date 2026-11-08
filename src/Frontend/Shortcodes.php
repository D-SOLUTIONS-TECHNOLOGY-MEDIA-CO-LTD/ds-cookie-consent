<?php
/**
 * Shortcodes that reopen the cookie preferences.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Settings\DefaultTexts;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	/**
	 * Register [dscc_cookie_settings], [dscc_cookie_table] and [dscc_last_scan].
	 */
	public static function register() {
		add_shortcode( 'dscc_cookie_settings', array( __CLASS__, 'cookie_settings' ) );
		add_shortcode( 'dscc_cookie_table', array( CookieTableShortcode::class, 'render' ) );
		add_shortcode( 'dscc_last_scan', array( __CLASS__, 'last_scan' ) );
	}

	/**
	 * Date the cookie list was last updated: the last scan (option dscc_last_scan, unix time or date string),
	 * otherwise the newest change of the confirmed catalog. Empty when there is neither.
	 *
	 * @param array|string $atts format: PHP date format (default d/m/Y).
	 * @return string
	 */
	public static function last_scan( $atts ) {
		$atts = shortcode_atts( array( 'format' => 'd/m/Y' ), $atts, 'dscc_last_scan' );
		$time = get_option( 'dscc_last_scan' );
		$time = is_numeric( $time ) ? (int) $time : ( is_string( $time ) ? (int) strtotime( $time ) : 0 );
		if ( $time <= 0 ) {
			$time = FrontendCatalogCache::updated_at();
		}
		return $time > 0 ? esc_html( wp_date( (string) $atts['format'], $time ) ) : '';
	}

	/**
	 * Link that opens the preferences (same result as the shield widget). In client-side language mode both
	 * languages are printed: the one for the server language is visible, the other is hidden until the
	 * front-end script picks the language from <html lang> (markup stays identical for every visitor).
	 *
	 * @param array|string $atts Shortcode attributes (text: one custom label for every language).
	 * @return string
	 */
	public static function cookie_settings( $atts ) {
		$atts   = shortcode_atts( array( 'text' => '' ), $atts, 'dscc_cookie_settings' );
		$server = LanguageResolver::current();
		$custom = '' !== trim( (string) $atts['text'] ) ? (string) $atts['text'] : (string) Settings::get( 'appearance', 'widget_label', '' );
		if ( ! LanguageResolver::is_client_side() ) {
			return self::link( '' !== $custom ? $custom : Settings::texts( $server )['widget_label'] );
		}
		$inner = '';
		$order = array_merge( array( $server ), array_diff( DefaultTexts::LANGS, array( $server ) ) );
		foreach ( $order as $lang ) {
			$label  = '' !== $custom ? $custom : Settings::texts( $lang )['widget_label'];
			$inner .= '<span data-dscc-lang="' . esc_attr( $lang ) . '"' . ( $lang === $server ? '' : ' hidden' ) . '>' . esc_html( $label ) . '</span>';
		}
		return self::link( $inner, true );
	}

	/**
	 * Anchor markup.
	 *
	 * @param string $label   Label text, or ready HTML when $is_html.
	 * @param bool   $is_html Whether $label is already escaped HTML.
	 * @return string
	 */
	private static function link( $label, $is_html = false ) {
		return '<a href="#cai-dat-cookie" data-dscc-open' . ( $is_html ? ' translate="no"' : '' ) . '>' . ( $is_html ? $label : esc_html( $label ) ) . '</a>';
	}
}
