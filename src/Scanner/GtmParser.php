<?php
/**
 * Reads the code of a Google Tag Manager container (gtm.js) for the tags it fires: Google tag ids (G-, AW-, DC-, UA-)
 * and the URLs of the vendor scripts and pixels it loads. Pure: no WordPress calls.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

final class GtmParser {

	const MAX_URLS = 200;

	/** Hosts that appear in every container and say nothing about the vendors it loads. */
	const SKIP_HOSTS = array( 'www.w3.org', 'schema.org', 'www.googletagmanager.com', 'googletagmanager.com' );

	/**
	 * Parse container code.
	 *
	 * @param string $js Container JavaScript.
	 * @return array {tag_ids: string[], urls: string[]}
	 */
	public static function parse( $js ) {
		// Strings in the container escape slashes ("https:\/\/host\/path") or use /.
		$js = str_ireplace( array( '\\u002f', '\\/' ), '/', (string) $js );

		$ids = array();
		if ( preg_match_all( '~(?<![A-Za-z0-9_\-])((?:G|AW|DC)-[A-Z0-9]{6,14}|UA-\d{4,10}-\d{1,3})(?![A-Za-z0-9])~', $js, $m ) ) {
			$ids = array_values( array_unique( $m[1] ) );
		}
		$urls = array();
		if ( preg_match_all( '~(?:https?:)?//[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}[^\s"\'\\\\<>)]{0,200}~i', $js, $all ) ) {
			foreach ( $all[0] as $url ) {
				$url  = 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
				$host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
				if ( '' === $host || in_array( $host, self::SKIP_HOSTS, true ) ) {
					continue;
				}
				$urls[ $url ] = true;
				if ( count( $urls ) >= self::MAX_URLS ) {
					break;
				}
			}
		}
		return array(
			'tag_ids' => $ids,
			'urls'    => array_keys( $urls ),
		);
	}

	/**
	 * Script URL that a known-database pattern recognises for a tag id.
	 *
	 * @param string $tag_id Google tag id.
	 * @return string
	 */
	public static function tag_url( $tag_id ) {
		return 'https://www.googletagmanager.com/gtag/js?id=' . rawurlencode( $tag_id );
	}
}
