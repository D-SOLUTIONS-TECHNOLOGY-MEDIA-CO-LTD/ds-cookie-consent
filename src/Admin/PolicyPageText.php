<?php
/**
 * Plain text of the configured cookie policy page, with the cookie table shortcode expanded, so the compliance report
 * can tell which cookies the policy actually mentions. Pages built with a page builder that keeps its content outside
 * post_content are read through the builder's data when it is JSON (Elementor); the filter `dscc_policy_page_text`
 * lets other setups supply the text.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class PolicyPageText {

	/**
	 * The policy text.
	 *
	 * @return array {state: ok|no_page|empty, page_id: int, text: string}
	 */
	public static function get() {
		$id = (int) Settings::get( 'content', 'cookie_policy_page_id', 0 );
		if ( $id <= 0 ) {
			$url = (string) Settings::get( 'content', 'cookie_policy_url', '' );
			$id  = '' !== $url ? (int) url_to_postid( '/' === $url[0] ? home_url( $url ) : $url ) : 0;
		}
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post ) {
			return array(
				'state'   => 'no_page',
				'page_id' => 0,
				'text'    => '',
			);
		}
		$content = (string) $post->post_content;
		$builder = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $builder ) && '' !== $builder ) {
			$content .= ' ' . $builder;
		}
		// Only our own shortcode is expanded: other shortcodes may have side effects on an admin screen.
		$content = preg_replace_callback(
			'/' . get_shortcode_regex( array( 'dscc_cookie_table' ) ) . '/',
			static function ( $m ) {
				return do_shortcode( $m[0] );
			},
			$content
		);
		$text    = html_entity_decode( wp_strip_all_tags( str_replace( '<', ' <', (string) $content ) ), ENT_QUOTES );
		$text    = (string) apply_filters( 'dscc_policy_page_text', $text, (int) $post->ID );
		return array(
			'state'   => '' === trim( $text ) ? 'empty' : 'ok',
			'page_id' => (int) $post->ID,
			'text'    => $text,
		);
	}

	/**
	 * Does the text mention a cookie? A name must stand alone (so "_ga" is not found in "_gat"); a pattern ("_ga_*") is
	 * found by the part before the first "*", which may continue ("_ga_<mã>").
	 *
	 * @param string $text Policy text.
	 * @param string $name Stored cookie name.
	 * @return bool
	 */
	public static function mentions( $text, $name ) {
		$is_pattern = false !== strpos( $name, '*' );
		$needle     = $is_pattern ? (string) strstr( $name, '*', true ) : $name;
		if ( $is_pattern && strlen( $needle ) < 3 ) {
			$needle = str_replace( '*', '', $name );
		}
		if ( '' === $needle ) {
			return false;
		}
		$re = '/(?<![A-Za-z0-9_])' . preg_quote( $needle, '/' ) . ( $is_pattern ? '' : '(?![A-Za-z0-9_])' ) . '/';
		return 1 === preg_match( $re, $text );
	}
}
