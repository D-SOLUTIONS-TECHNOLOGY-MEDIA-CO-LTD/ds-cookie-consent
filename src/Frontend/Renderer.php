<?php
/**
 * Prints the banner markup in the footer and decides where the plugin is allowed to run.
 * Output is identical for every visitor (page-cache safe); the client decides what to show.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\CssSanitizer;

defined( 'ABSPATH' ) || exit;

final class Renderer {

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'wp_footer', array( __CLASS__, 'print_footer' ), 100 );
	}

	/**
	 * Common context check: front end, not an editor/preview/feed/embed request.
	 *
	 * @return bool
	 */
	private static function is_front_context() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_embed() || is_customize_preview() || is_robots() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		// Page builders render their own editors/previews.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only context checks.
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['et_fb'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['brizy-edit'] ) || isset( $_GET['bricks'] ) || isset( $_GET['ct_builder'] ) || isset( $_GET['oxygen_iframe'] ) || isset( $_GET['et_pb_preview'] ) || isset( $_GET['vc_editable'] ) || isset( $_GET['tve'] ) || isset( $_GET['dscc_preview'] ) ) {
			return false;
		}
		return (bool) apply_filters( 'dscc_should_render', true );
	}

	/**
	 * Head script (Consent Mode defaults) runs on every front page, including embedded policy pages,
	 * so tracking tags inside them stay denied until consent.
	 *
	 * @return bool
	 */
	public static function should_print_head() {
		return self::is_front_context();
	}

	/**
	 * Banner markup and assets run on front pages except the embedded policy view.
	 *
	 * @return bool
	 */
	public static function should_print_banner() {
		return self::is_front_context() && ! EmbedMode::is_embed_request();
	}

	/**
	 * Footer output: banner root, modal template, data and custom CSS.
	 */
	public static function print_footer() {
		if ( ! self::should_print_banner() ) {
			return;
		}
		self::print_markup( ViewModel::build() );
	}

	/**
	 * Print the full markup for a view model (shared with the admin preview).
	 *
	 * @param array $vm View model.
	 */
	public static function print_markup( array $vm ) {
		Template::render( $vm['layout'] . '.php', $vm );

		echo '<template id="dscc-tpl-modal">';
		Template::render( 'partials/modal.php', $vm );
		echo '</template>' . "\n";

		$json = wp_json_encode( $vm['data'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with HEX flags, safe inside a script element.
		echo '<script type="application/json" id="dscc-data">' . $json . "</script>\n";

		$custom = CssSanitizer::scope( (string) $vm['settings']['appearance']['custom_css'] );
		if ( '' !== $custom ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitised by CssSanitizer (no "<", no @import, no javascript: urls).
			echo '<style id="dscc-custom-css">' . $custom . "</style>\n";
		}
	}
}
