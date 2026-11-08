<?php
/**
 * Downloads the package of this plugin during an update: adds the optional token for api.github.com (never forwarded
 * to the redirect target), and checks the SHA-256 digest GitHub publishes for the asset.
 *
 * What to expect comes from the update item that was injected into the core update list (it carries the package URL,
 * the asset API URL and the digest), with the release cache as a second source. An update of this plugin for which
 * neither source knows the package is refused, so an expired cache can never turn the check off.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Update;

defined( 'ABSPATH' ) || exit;

final class PackageDownloader {

	/**
	 * What is known about the package being installed.
	 *
	 * @param string $package Package URL asked for by the upgrader.
	 * @return array|null {package, api_url, digest} or null when this package is not one of ours.
	 */
	public static function expectation( $package ) {
		$base    = plugin_basename( DSCC_FILE );
		$updates = get_site_transient( 'update_plugins' );
		if ( is_object( $updates ) && isset( $updates->response[ $base ] ) && is_object( $updates->response[ $base ] ) ) {
			$item = $updates->response[ $base ];
			$urls = array( isset( $item->dscc_package ) ? $item->dscc_package : '', isset( $item->dscc_api_url ) ? $item->dscc_api_url : '' );
			if ( in_array( $package, $urls, true ) ) {
				return array(
					'package' => (string) $item->dscc_package,
					'api_url' => (string) $item->dscc_api_url,
					'digest'  => isset( $item->dscc_digest ) ? (string) $item->dscc_digest : '',
				);
			}
		}
		$release = ReleaseFeed::latest( false );
		if ( $release && in_array( $package, array( $release['package'], $release['api_url'] ), true ) ) {
			return array(
				'package' => $release['package'],
				'api_url' => $release['api_url'],
				'digest'  => $release['digest'],
			);
		}
		return null;
	}

	/**
	 * Filter callback for upgrader_pre_download.
	 *
	 * @param mixed  $reply      Earlier reply (false = let core download).
	 * @param string $package    Package URL.
	 * @param object $upgrader   Upgrader.
	 * @param array  $hook_extra Hook data.
	 * @return mixed Path of the downloaded file, WP_Error, or $reply unchanged.
	 */
	public static function download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		unset( $upgrader );
		if ( false !== $reply || ! is_string( $package ) ) {
			return $reply;
		}
		$ours = is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && plugin_basename( DSCC_FILE ) === $hook_extra['plugin'];
		if ( is_array( $hook_extra ) && isset( $hook_extra['plugin'] ) && ! $ours ) {
			return $reply;
		}
		$expected = self::expectation( $package );
		if ( null === $expected ) {
			if ( $ours && 0 === strpos( $package, 'https://' ) && self::looks_like_release_asset( $package ) ) {
				return new \WP_Error( 'dscc_unknown_package', __( 'The update information has expired. Check for updates again and retry.', 'ds-cookie-consent' ) );
			}
			return $reply;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$file = self::fetch( $package );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( '' !== $expected['digest'] && ! hash_equals( $expected['digest'], (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'dscc_bad_digest', __( 'The downloaded update does not match the checksum published by GitHub, so it was discarded.', 'ds-cookie-consent' ) );
		}
		return $file;
	}

	/**
	 * Whether a URL points at a GitHub release asset or the GitHub API (so an unknown one is suspicious).
	 *
	 * @param string $package URL.
	 * @return bool
	 */
	private static function looks_like_release_asset( $package ) {
		$host = wp_parse_url( $package, PHP_URL_HOST );
		return in_array( $host, array( 'github.com', ReleaseFeed::API_HOST ), true );
	}

	/**
	 * Fetch the zip to a temporary file.
	 *
	 * @param string $package Package URL.
	 * @return string|\WP_Error Temporary file path.
	 */
	private static function fetch( $package ) {
		if ( ReleaseFeed::API_HOST !== wp_parse_url( $package, PHP_URL_HOST ) || '' === ReleaseFeed::token() ) {
			// Public asset (or no token): the plain core download, without any credential.
			return download_url( $package, 300 );
		}
		$tmp = wp_tempnam( $package );
		if ( ! $tmp ) {
			return new \WP_Error( 'dscc_no_temp_file', __( 'Could not create a temporary file for the update.', 'ds-cookie-consent' ) );
		}
		$response = wp_remote_get(
			$package,
			array(
				'timeout'     => 300,
				'redirection' => 0,
				'stream'      => true,
				'filename'    => $tmp,
				'headers'     => ReleaseFeed::headers( $package, 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			// The signed storage URL needs no credential and must not receive ours.
			wp_delete_file( $tmp );
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			if ( 0 !== strpos( $location, 'https://' ) ) {
				return new \WP_Error( 'dscc_bad_redirect', __( 'GitHub answered with an unexpected download address.', 'ds-cookie-consent' ) );
			}
			return download_url( $location, 300 );
		}
		if ( 200 !== $code ) {
			wp_delete_file( $tmp );
			/* translators: %d: HTTP status code */
			return new \WP_Error( 'dscc_download_failed', sprintf( __( 'GitHub refused the update download (HTTP %d).', 'ds-cookie-consent' ), $code ) );
		}
		return $tmp;
	}
}
