<?php
/**
 * E2E fixture (wp eval-file): installs or removes the vendor snippet mu-plugin and sets its flags.
 * Usage: wp eval-file <path> <command> [flags...]
 *   set <flag> [<flag>...]   install the mu-plugin and show these snippets (ga ordered meta clarity youtube pixel gtm server_cookies unknown_cookie gtm_stub scan_loopback)
 *   off                      remove the mu-plugin and the flags
 */

$command = isset( $args[0] ) ? $args[0] : '';
$target  = WPMU_PLUGIN_DIR . '/dscc-e2e-vendors.php';

if ( 'set' === $command ) {
	if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
		mkdir( WPMU_PLUGIN_DIR, 0777, true );
	}
	copy( __DIR__ . '/vendors-mu-plugin.php', $target );
	update_option( 'dscc_e2e_vendors', array_slice( $args, 1 ), false );
} elseif ( 'off' === $command ) {
	if ( file_exists( $target ) ) {
		unlink( $target );
	}
	delete_option( 'dscc_e2e_vendors' );
} else {
	WP_CLI::error( 'unknown command ' . $command );
}
echo "vendors ok\n";
