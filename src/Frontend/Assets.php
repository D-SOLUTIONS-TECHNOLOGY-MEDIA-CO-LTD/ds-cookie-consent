<?php
/**
 * Front-end assets: one deferred JS bundle and only the CSS file of the active layout.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class Assets {

	const HANDLE_JS  = 'dscc';
	const HANDLE_CSS = 'dscc';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 2 );
		add_filter( 'style_loader_tag', array( __CLASS__, 'style_tag' ), 10, 2 );
	}

	/**
	 * Cache-busting version: hash of the file content, so a rebuild always invalidates caches.
	 *
	 * @param string $relative Path under the plugin directory.
	 * @return string
	 */
	public static function version( $relative ) {
		static $cache = array();
		if ( ! isset( $cache[ $relative ] ) ) {
			$path               = DSCC_DIR . $relative;
			$cache[ $relative ] = is_readable( $path ) ? substr( (string) md5_file( $path ), 0, 10 ) : DSCC_VERSION;
		}
		return $cache[ $relative ];
	}

	/**
	 * CSS file for a layout.
	 *
	 * @param string $layout card|bar.
	 * @return string Relative path.
	 */
	public static function css_path( $layout ) {
		return 'assets/dist/dscc-' . ( 'bar' === $layout ? 'bar' : 'card' ) . '.css';
	}

	/**
	 * Enqueue the layout CSS and the script.
	 */
	public static function enqueue() {
		if ( ! Renderer::should_print_banner() ) {
			return;
		}
		$layout = 'bar' === Settings::get( 'appearance', 'layout' ) ? 'bar' : 'card';
		$css    = self::css_path( $layout );
		wp_enqueue_style( self::HANDLE_CSS, DSCC_URL . $css, array(), self::version( $css ) );
		wp_enqueue_script( self::HANDLE_JS, DSCC_URL . 'assets/dist/dscc.js', array(), self::version( 'assets/dist/dscc.js' ), false );
	}

	/**
	 * Defer our script and keep optimisation plugins from touching it.
	 *
	 * @param string $tag    Tag HTML.
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function script_tag( $tag, $handle ) {
		if ( self::HANDLE_JS !== $handle ) {
			return $tag;
		}
		return str_replace( ' src=', ' defer data-dscc-ignore data-no-optimize="1" data-cfasync="false" src=', $tag );
	}

	/**
	 * Keep optimisation plugins from inlining or deferring the layout CSS.
	 *
	 * @param string $tag    Tag HTML.
	 * @param string $handle Handle.
	 * @return string
	 */
	public static function style_tag( $tag, $handle ) {
		if ( self::HANDLE_CSS !== $handle ) {
			return $tag;
		}
		return str_replace( '<link ', '<link data-no-optimize="1" ', $tag );
	}
}
