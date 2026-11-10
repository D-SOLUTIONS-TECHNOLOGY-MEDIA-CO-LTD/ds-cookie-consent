<?php
/**
 * Plugin Name:       DS Cookie Consent
 * Plugin URI:        https://github.com/D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent
 * Description:       Cookie consent banner with Google Consent Mode v2, consent log and live-preview settings. Reusable across many sites.
 * Version:           1.0.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Update URI:        https://github.com/D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent
 * Author:            D-Solutions
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ds-cookie-consent
 * Domain Path:       /languages
 *
 * @package DSolutions\CookieConsent
 */

defined( 'ABSPATH' ) || exit;

define( 'DSCC_VERSION', '1.0.1' );
define( 'DSCC_FILE', __FILE__ );
define( 'DSCC_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSCC_URL', plugin_dir_url( __FILE__ ) );

/*
 * Fallback PSR-4 autoloader: the plugin must run without `composer install`.
 * Only the prefixed third-party libraries in vendor-prefixed/ are required at runtime.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'DSolutions\\CookieConsent\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = DSCC_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

if ( is_readable( DSCC_DIR . 'vendor-prefixed/autoload.php' ) ) {
	require_once DSCC_DIR . 'vendor-prefixed/autoload.php';
}

// The Meta Pixel plugin reads its hold filter while it loads, which can be before plugins_loaded.
DSolutions\CookieConsent\Integrations\MetaPixel::register_early();

register_activation_hook( __FILE__, array( 'DSolutions\\CookieConsent\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DSolutions\\CookieConsent\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		DSolutions\CookieConsent\Plugin::instance()->boot();
	}
);
