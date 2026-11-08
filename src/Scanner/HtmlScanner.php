<?php
/**
 * Reads a page's HTML for third-party resources (script src, inline scripts, iframes, images) and matches them
 * against the known database's script patterns to learn which platforms the page uses. Understands both the original
 * markup and the blocker's rewritten markup (data-dscc-src). Pure: no WordPress calls.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Blocker\HtmlAttributes;
use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Catalog\PatternMatcher;

final class HtmlScanner {

	/** Most resources read from one page, and the longest inline script body kept for matching. */
	const MAX_RESOURCES = 400;
	const MAX_INLINE    = 20000;

	/** Well-known CDN, font and WordPress hosts: not tracking tools, left out of the "unknown host" list. */
	const COMMON_HOSTS = array( 'fonts.googleapis.com', 'fonts.gstatic.com', 'ajax.googleapis.com', 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'code.jquery.com', 'maxcdn.bootstrapcdn.com', 'stackpath.bootstrapcdn.com', 'use.fontawesome.com', 'kit.fontawesome.com', 's.w.org', 'secure.gravatar.com', 'gravatar.com', 'stats.wp.com', 'wp.com' );

	/** Container id as used in the snippet and the noscript iframe. */
	const GTM_ID = 'GTM-[A-Z0-9]{4,12}';

	/**
	 * Collect resources and Tag Manager container ids.
	 *
	 * @param string $html Page HTML.
	 * @return array {resources: array[] {kind, src, inline}, gtm_ids: string[]}
	 */
	public static function extract( $html ) {
		$html      = (string) $html;
		$resources = array();
		$gtm       = array();
		$found     = preg_match_all( '~<(script|iframe|img)(?=[\s/>])([^>]*)>~i', $html, $hits, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( ! $found ) {
			return array(
				'resources' => array(),
				'gtm_ids'   => array(),
			);
		}
		$skip = 0;
		foreach ( $hits as $hit ) {
			if ( count( $resources ) >= self::MAX_RESOURCES ) {
				break;
			}
			$start = $hit[0][1];
			if ( $start < $skip ) {
				continue; // Inside a script body.
			}
			$kind  = strtolower( $hit[1][0] );
			$attrs = HtmlAttributes::parse( $hit[2][0] );
			if ( null === $attrs ) {
				continue;
			}
			$src    = self::source( $attrs );
			$inline = '';
			if ( 'script' === $kind ) {
				$after = $start + strlen( $hit[0][0] );
				$end   = stripos( $html, '</script', $after );
				$skip  = false === $end ? strlen( $html ) : $end;
				if ( '' === $src ) {
					$inline = substr( $html, $after, min( $skip - $after, self::MAX_INLINE ) );
				}
			}
			if ( '' === $src && '' === $inline ) {
				continue;
			}
			$resources[] = array(
				'kind'   => $kind,
				'src'    => $src,
				'inline' => $inline,
			);
			$gtm         = array_merge( $gtm, self::gtm_ids( $src, $inline ) );
		}
		return array(
			'resources' => $resources,
			'gtm_ids'   => array_values( array_unique( $gtm ) ),
		);
	}

	/**
	 * Match resources against the known database.
	 *
	 * @param array[] $resources Output of extract().
	 * @param KnownDb $known     Known database.
	 * @param string  $own_host  Host of the site (its own and sub-hosts are not reported as third-party).
	 * @return array {services: array<string,string> slug => evidence, unknown_hosts: string[]}
	 */
	public static function detect( array $resources, KnownDb $known, $own_host ) {
		$services = array();
		$unknown  = array();
		$own_host = strtolower( (string) $own_host );
		$defs     = array();
		foreach ( $known->services() as $service ) {
			if ( array_filter( $service['script_patterns'] ) ) {
				$defs[] = $service;
			}
		}
		foreach ( $resources as $res ) {
			$matched = false;
			foreach ( $defs as $service ) {
				if ( PatternMatcher::script_matches( $service['script_patterns'], $res['src'], $res['inline'] ) ) {
					$matched = true;
					if ( ! isset( $services[ $service['slug'] ] ) ) {
						$host                         = PatternMatcher::host_of( $res['src'] );
						$services[ $service['slug'] ] = '' !== $host ? $host : 'inline';
					}
				}
			}
			if ( ! $matched && '' !== $res['src'] && in_array( $res['kind'], array( 'script', 'iframe' ), true ) ) {
				$host = PatternMatcher::host_of( $res['src'] );
				if ( '' !== $host && ! PatternMatcher::host_matches( $host, self::COMMON_HOSTS ) && $host !== $own_host && ( '' === $own_host || substr( $host, -strlen( $own_host ) - 1 ) !== '.' . $own_host ) ) {
					$unknown[ $host ] = true;
				}
			}
		}
		return array(
			'services'      => $services,
			'unknown_hosts' => array_slice( array_keys( $unknown ), 0, 30 ),
		);
	}

	/**
	 * Absolute source URL of a tag ('' when it has none or it is relative).
	 *
	 * @param array $attrs Parsed attributes.
	 * @return string
	 */
	private static function source( array $attrs ) {
		foreach ( array( 'data-dscc-src', 'src', 'data-src', 'data-lazy-src' ) as $name ) {
			if ( isset( $attrs[ $name ]['value'] ) && '' !== trim( (string) $attrs[ $name ]['value'] ) ) {
				$url = html_entity_decode( trim( (string) $attrs[ $name ]['value'] ), ENT_QUOTES );
				if ( 0 === strpos( $url, '//' ) ) {
					$url = 'https:' . $url;
				}
				return preg_match( '~^https?://~i', $url ) ? $url : '';
			}
		}
		return '';
	}

	/**
	 * Tag Manager container ids named by a script URL or by an inline snippet that loads gtm.js.
	 *
	 * @param string $src    Source URL.
	 * @param string $inline Inline body.
	 * @return string[]
	 */
	private static function gtm_ids( $src, $inline ) {
		$ids = array();
		if ( '' !== $src && preg_match( '~googletagmanager\.com/(?:gtm\.js|ns\.html)\?(?:[^#]*&)?id=(' . self::GTM_ID . ')~', $src, $m ) ) {
			$ids[] = $m[1];
		}
		if ( '' !== $inline && false !== stripos( $inline, 'googletagmanager.com/gtm.js' ) && preg_match_all( '~(?<![A-Za-z0-9-])(' . self::GTM_ID . ')(?![A-Za-z0-9])~', $inline, $all ) ) {
			$ids = array_merge( $ids, $all[1] );
		}
		return $ids;
	}
}
