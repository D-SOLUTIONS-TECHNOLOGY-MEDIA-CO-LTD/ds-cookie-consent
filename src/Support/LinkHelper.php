<?php
/**
 * Single place that builds links to third-party platform privacy policies (a service's privacy_url).
 * Rule: always opens in a new tab with rel="nofollow noopener noreferrer".
 * The same attributes are applied in assets/src/js/external-link.js for client-built links.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Support;

final class LinkHelper {

	const REL    = 'nofollow noopener noreferrer';
	const TARGET = '_blank';

	/**
	 * Anchor HTML for an external policy link. Returns an empty string for non-http(s) URLs.
	 *
	 * @param string $url   Policy URL.
	 * @param string $label Visible text (plain text, escaped here unless $is_html).
	 * @param bool   $is_html True when $label is already safe HTML (for example language spans).
	 * @return string Safe HTML.
	 */
	public static function external_policy_link( $url, $label, $is_html = false ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return '<a href="' . esc_url( $url ) . '" target="' . self::TARGET . '" rel="' . self::REL . '">' . ( $is_html ? (string) $label : esc_html( (string) $label ) ) . '</a>';
	}
}
