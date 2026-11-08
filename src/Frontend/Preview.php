<?php
/**
 * Admin live-preview page: a bare HTML page (no theme) that renders the real frontend template.
 * URL: /?dscc_preview=1&_wpnonce=...&layout=card|bar&view=banner|prefs|widget&ov[...]=structural overrides
 * Colours, texts and logos are applied in the browser through postMessage (assets/src/js/preview-bridge.js).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class Preview {

	const NONCE_ACTION = 'dscc_preview';

	/** Appearance fields that change the server-rendered structure (everything else is live via postMessage). */
	const STRUCTURAL = array( 'layout', 'button_order', 'customize_style', 'card_position', 'widget_side', 'widget_enabled', 'per_service_toggle', 'logo_id', 'logo_popup_id', 'widget_show', 'widget_label' );

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 0 );
	}

	/**
	 * Preview URL for the admin screen.
	 *
	 * @return string
	 */
	public static function base_url() {
		return add_query_arg(
			array(
				'dscc_preview' => '1',
				'_wpnonce'     => wp_create_nonce( self::NONCE_ACTION ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Render the preview page and stop.
	 */
	public static function maybe_render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified right below.
		if ( empty( $_GET['dscc_preview'] ) ) {
			return;
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'The preview link is invalid or has expired.', 'ds-cookie-consent' ), 403 );
		}

		$appearance = self::appearance_with_overrides();
		$vm         = ViewModel::build(
			array(
				'preview'    => true,
				'appearance' => $appearance,
			)
		);

		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'banner';
		$view = in_array( $view, array( 'banner', 'prefs', 'widget' ), true ) ? $view : 'banner';

		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		$css = Assets::css_path( $vm['layout'] );
		$js  = 'assets/dist/dscc.js';
		?>
<!doctype html>
<html lang="<?php echo esc_attr( $vm['lang'] ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php esc_html_e( 'DS Cookie Consent preview', 'ds-cookie-consent' ); ?></title>
<style>html,body{margin:0;min-height:100vh;background:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1d2939}.pv{max-width:760px;margin:0 auto;padding:40px 24px}.pv h1{font-size:28px;margin:0 0 12px}.pv p{line-height:1.6;color:#475467}.pv .bar{height:12px;border-radius:6px;background:#eaecf0;margin:0 0 12px}.pv .img{height:220px;border-radius:12px;background:#f2f4f7;margin:24px 0}</style>
<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone preview page without the theme/enqueue pipeline. ?>
<link rel="stylesheet" href="<?php echo esc_url( DSCC_URL . $css . '?ver=' . Assets::version( $css ) ); ?>">
<script data-dscc-ignore>window.DSCC_BOOT={skip:false,consent:null,preview:true,view:<?php echo wp_json_encode( $view ); ?>,cfg:{}};</script>
<?php // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone preview page without the theme/enqueue pipeline. ?>
<script defer data-dscc-ignore src="<?php echo esc_url( DSCC_URL . $js . '?ver=' . Assets::version( $js ) ); ?>"></script>
</head>
<body>
<div class="pv" aria-hidden="true"><h1><?php echo esc_html( $vm['site_name'] ); ?></h1><div class="bar" style="width:90%"></div><div class="bar" style="width:75%"></div><div class="bar" style="width:82%"></div><div class="img"></div><div class="bar" style="width:88%"></div><div class="bar" style="width:60%"></div></div>
		<?php
		Renderer::print_markup( $vm );
		?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Saved appearance settings with the structural overrides from the query string applied.
	 *
	 * @return array
	 */
	private static function appearance_with_overrides() {
		$current = Settings::group( 'appearance' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by the caller; every value goes through SettingsSchema::sanitize_group().
		$raw = isset( $_GET['ov'] ) && is_array( $_GET['ov'] ) ? wp_unslash( $_GET['ov'] ) : array();
		$ov  = array();
		foreach ( self::STRUCTURAL as $key ) {
			if ( isset( $raw[ $key ] ) && ! is_array( $raw[ $key ] ) ) {
				$ov[ $key ] = $raw[ $key ];
			}
		}
		if ( isset( $_GET['layout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$ov['layout'] = sanitize_key( wp_unslash( $_GET['layout'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return SettingsSchema::sanitize_group( 'appearance', array_merge( $current, $ov ), $current );
	}
}
