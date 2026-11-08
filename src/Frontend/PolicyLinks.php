<?php
/**
 * Policy links (cookie policy, privacy) and the in-text link markup.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

defined( 'ABSPATH' ) || exit;

final class PolicyLinks {

	/**
	 * Policy URLs and embed behaviour. URLs are pre-resolved (see ResolvedCache).
	 *
	 * @param array $content  Content settings.
	 * @param array $resolved Resolved cache data.
	 * @return array
	 */
	public static function build( array $content, array $resolved ) {
		$cookie  = (string) $resolved['cookie_url'];
		$privacy = (string) $resolved['privacy_url'];
		$mode    = 'link' === $content['policy_embed_mode'] ? 'link' : 'iframe';

		return array(
			'mode'          => $mode,
			'cookie_url'    => $cookie,
			'privacy_url'   => $privacy,
			'cookie_frame'  => 'iframe' === $mode && self::is_same_host( $cookie ) ? add_query_arg( 'dscc_embed', '1', $cookie ) : '',
			'privacy_frame' => 'iframe' === $mode && self::is_same_host( $privacy ) ? add_query_arg( 'dscc_embed', '1', $privacy ) : '',
		);
	}

	/**
	 * True when the URL lives on this site's host (iframe embedding is only offered for these).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_same_host( $url ) {
		if ( '' === $url ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$mine = wp_parse_url( home_url(), PHP_URL_HOST );
		return $host && $mine && strtolower( $host ) === strtolower( $mine );
	}

	/**
	 * Policy link: opens the cookie tab for the bar layout; the policy page (new tab) for the card layout.
	 *
	 * @param array       $vm   View model.
	 * @param string|null $lang Language of the label (default: the resolved language).
	 * @return string Safe HTML.
	 */
	public static function link_html( array $vm, $lang = null ) {
		$label = esc_html( Text::raw( $vm, null === $lang ? $vm['lang'] : $lang, 'policy_link' ) );
		if ( 'card' === $vm['layout'] && '' !== $vm['policy']['cookie_url'] ) {
			return '<a href="' . esc_url( $vm['policy']['cookie_url'] ) . '" target="_blank" rel="noopener" data-dscc-t="policy_link">' . $label . '</a>';
		}
		return '<a href="#" data-dscc-action="open-tab" data-dscc-tab="cookies" data-dscc-t="policy_link">' . $label . '</a>';
	}
}
