<?php
/**
 * Builds the data shared by the PHP templates and the client JSON (#dscc-data).
 * Pure data preparation: no output.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\DefaultTexts;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class ViewModel {

	/**
	 * Build the view model.
	 *
	 * @param array $opts preview (bool), appearance (array override).
	 * @return array
	 */
	public static function build( array $opts = array() ) {
		$settings = Settings::all();
		$override = ! empty( $opts['appearance'] ) && is_array( $opts['appearance'] );
		if ( $override ) {
			$settings['appearance'] = $opts['appearance'];
		}
		$a       = $settings['appearance'];
		$adv     = $settings['advanced'];
		$layout  = 'bar' === $a['layout'] ? 'bar' : 'card';
		$preview = ! empty( $opts['preview'] );
		$lang    = LanguageResolver::current();
		$dual    = ! $preview && LanguageResolver::is_client_side();
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		// The admin preview overrides appearance with unsaved values, so it resolves live instead of using the cache.
		$resolved = $override ? ResolvedCache::resolve( $a, $settings['content'] ) : ResolvedCache::get();

		$by_lang = array();
		foreach ( $dual ? DefaultTexts::LANGS : array( $lang ) as $code ) {
			$by_lang[ $code ] = self::texts( $code, $site, (string) $a['widget_label'] );
		}

		$order = (string) $a['button_order'];
		if ( 'accept_first' !== $order && 'accept_last' !== $order ) {
			$order = 'bar' === $layout ? 'accept_first' : 'accept_last';
		}

		$show_functional = ! empty( $adv['show_functional'] );
		$all_services    = ServiceCatalog::services( $show_functional, 0 );
		$services        = array_slice( $all_services, 0, ServiceCatalog::MAX_SERVICES );

		$vm         = array(
			'layout'            => $layout,
			'lang'              => $lang,
			'dual'              => $dual,
			'settings'          => $settings,
			'texts'             => $by_lang[ $lang ],
			'texts_by_lang'     => $by_lang,
			'order'             => $order,
			'categories'        => ServiceCatalog::categories( $services, $show_functional ),
			'services'          => $services,
			// Services beyond the toggle cap still appear in the generated cookie policy tab, so none is hidden.
			'policy_categories' => ServiceCatalog::categories( $all_services, $show_functional ),
			'overflow'          => count( $all_services ) - count( $services ),
			'per_service'       => ! empty( $a['per_service_toggle'] ) && ! empty( $services ),
			'policy'            => PolicyLinks::build( $settings['content'], $resolved ),
			'logo'              => $resolved['logo'],
			'logo_popup'        => $resolved['logo_popup'],
			'site_name'         => $site,
			// The card layout has no other way to change a choice, so its widget is always on.
			'widget'            => 'card' === $layout ? true : ! empty( $a['widget_enabled'] ),
			'preview'           => $preview,
			'style'             => CssVars::style( $a, $layout ),
			'modal_mode'        => 'bar' === $layout ? 'full' : 'info',
		);
		$vm['data'] = ClientData::build( $vm );
		return $vm;
	}

	/**
	 * Texts for one language: defaults plus admin overrides, with {site} and the custom widget label applied.
	 *
	 * @param string $lang         vi|en.
	 * @param string $site         Site name.
	 * @param string $widget_label Custom widget label ('' = use the text).
	 * @return array<string,string>
	 */
	private static function texts( $lang, $site, $widget_label ) {
		$texts = Settings::texts( $lang );
		foreach ( $texts as $key => $value ) {
			$texts[ $key ] = str_replace( '{site}', $site, $value );
		}
		if ( '' !== $widget_label ) {
			$texts['widget_label'] = $widget_label;
		}
		return $texts;
	}

	/**
	 * Inline style attribute value with all CSS custom properties.
	 *
	 * @param array  $a      Appearance settings.
	 * @param string $layout card|bar.
	 * @return string
	 */
	public static function css_vars( array $a, $layout ) {
		return CssVars::style( $a, $layout );
	}

	/**
	 * CSS custom property map (also used by the live preview).
	 *
	 * @param array  $a      Appearance settings.
	 * @param string $layout card|bar.
	 * @return array<string,string>
	 */
	public static function css_var_map( array $a, $layout ) {
		return CssVars::map( $a, $layout );
	}

	/**
	 * Message HTML: escapes the text and swaps {policy_link} for the link markup (a span per language in dual mode).
	 *
	 * @param array  $vm  View model.
	 * @param string $key Text key.
	 * @return string Safe HTML.
	 */
	public static function message_html( array $vm, $key ) {
		return Text::message( $vm, $key );
	}

	/**
	 * Policy link: opens the cookie tab for the bar layout; the policy page (new tab) for the card layout.
	 *
	 * @param array $vm View model.
	 * @return string Safe HTML.
	 */
	public static function policy_link_html( array $vm ) {
		return PolicyLinks::link_html( $vm );
	}
}
