<?php
/**
 * Chooses the pages a server scan visits (spec 6.2 A): the home page, shop pages, pages with forms, then the newest
 * posts of every public post type. Only addresses of this site are returned.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Catalog\PatternMatcher;

defined( 'ABSPATH' ) || exit;

final class UrlCollector {

	/** Newest posts per public post type, and pages picked for a form. */
	const PER_TYPE = 20;
	const PER_FORM = 10;

	/**
	 * Page addresses to scan, most important first.
	 *
	 * @param int $max Most addresses to return.
	 * @return string[]
	 */
	public static function collect( $max ) {
		$max  = max( 1, (int) $max );
		$urls = array( home_url( '/' ) );
		$urls = array_merge( $urls, self::woocommerce_urls() );
		foreach ( self::form_post_ids() as $id ) {
			$urls[] = (string) get_permalink( $id );
		}
		foreach ( self::latest_post_ids() as $id ) {
			$urls[] = (string) get_permalink( $id );
		}
		$urls = (array) apply_filters( 'dscc_scan_urls', $urls );

		$out  = array();
		$seen = array();
		foreach ( $urls as $url ) {
			$url = self::normalise( $url );
			if ( '' === $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$out[]        = $url;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Is this address on this site (same host as the home or site address)? Used before every request, so the scan
	 * can never be pointed at another host.
	 *
	 * @param string $url Address.
	 * @return bool
	 */
	public static function is_own_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['host'] ), self::own_hosts(), true ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return in_array( $port, self::own_ports(), true );
	}

	/**
	 * Ports a request to this site may use: the port of the home address; on the default web ports (80 and 443) a switch
	 * between http and https is fine, on any other port it is not.
	 *
	 * @return int[]
	 */
	public static function own_ports() {
		$parts = wp_parse_url( home_url() );
		$https = is_array( $parts ) && isset( $parts['scheme'] ) && 'https' === strtolower( $parts['scheme'] );
		$port  = is_array( $parts ) && isset( $parts['port'] ) ? (int) $parts['port'] : ( $https ? 443 : 80 );
		return in_array( $port, array( 80, 443 ), true ) ? array( 80, 443 ) : array( $port );
	}

	/**
	 * Host names of this site, each with its "www." and bare variant (sites often redirect between the two).
	 *
	 * @return string[]
	 */
	public static function own_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = PatternMatcher::host_of( $url );
			if ( '' === $host ) {
				continue;
			}
			$hosts[] = $host;
			if ( 0 === strpos( $host, 'www.' ) ) {
				$hosts[] = substr( $host, 4 );
			} elseif ( substr_count( $host, '.' ) >= 1 && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
				$hosts[] = 'www.' . $host;
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Clean address: own site only, no fragment.
	 *
	 * @param mixed $url Raw address.
	 * @return string
	 */
	private static function normalise( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url = (string) preg_replace( '/#.*$/', '', trim( $url ) );
		return self::is_own_url( $url ) ? $url : '';
	}

	/**
	 * Shop, cart, checkout and account pages.
	 *
	 * @return string[]
	 */
	private static function woocommerce_urls() {
		if ( ! function_exists( 'wc_get_page_permalink' ) ) {
			return array();
		}
		$out = array();
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $page ) {
			$out[] = (string) wc_get_page_permalink( $page );
		}
		return $out;
	}

	/**
	 * Published posts that hold a form: Contact Form 7 or other shortcode forms, and Elementor forms.
	 *
	 * @return int[]
	 */
	private static function form_post_ids() {
		global $wpdb;
		$types = self::public_types();
		if ( ! $types ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$in}) AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s) ORDER BY post_modified DESC LIMIT %d";
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $types, array( '%' . $wpdb->esc_like( '[contact-form-7' ) . '%', '%' . $wpdb->esc_like( '[wpforms' ) . '%', '%' . $wpdb->esc_like( '[gravityform' ) . '%', self::PER_FORM ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- core table, placeholders generated above.

		$elementor = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_status = 'publish' AND m.meta_key = '_elementor_data' AND m.meta_value LIKE %s ORDER BY p.post_modified DESC LIMIT %d", '%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%', self::PER_FORM ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one bounded lookup per scan.
		return array_values( array_unique( array_map( 'intval', array_merge( (array) $ids, (array) $elementor ) ) ) );
	}

	/**
	 * Newest published posts of every public post type.
	 *
	 * @return int[]
	 */
	private static function latest_post_ids() {
		$ids = array();
		foreach ( self::public_types() as $type ) {
			$found = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'posts_per_page'   => self::PER_TYPE,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);
			$ids   = array_merge( $ids, array_map( 'intval', $found ) );
		}
		return $ids;
	}

	/**
	 * Public post types except attachments.
	 *
	 * @return string[]
	 */
	private static function public_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}
}
